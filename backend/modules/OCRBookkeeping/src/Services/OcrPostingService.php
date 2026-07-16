<?php

namespace Modules\OCRBookkeeping\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\ReversalRequest;
use App\Accounting\Services\AccountMappingService;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Warehouse;
use App\Services\FiscalPeriodValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Services\InventoryService;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\OCRBookkeeping\Models\OcrDocument;

/**
 * OCR Posting Service
 *
 * Posts verified OCR documents to the general ledger
 * Implements double-entry bookkeeping with atomic transactions
 */
class OcrPostingService
{
    public function __construct(
        private FiscalPeriodValidator $fiscalValidator,
        private DimensionValidationService $dimensionValidator,
        private ExchangeRateService $exchangeRateService,
        private PostingEngineInterface $postingEngine,
        private AccountMappingService $accountMapper,
        private InventoryService $inventoryService,
    ) {}

    /**
     * Post OCR document to ledger
     *
     * @throws \Exception
     */
    public function postDocument(OcrDocument $document): JournalEntry
    {
        // Validate document is ready for posting
        if (! $document->canBePosted()) {
            throw new \Exception('Document is not ready to be posted');
        }

        // Validate fiscal period
        $fiscalValidation = $this->fiscalValidator->validateDate($document->document_date);
        if (! $fiscalValidation['allowed']) {
            throw new \Exception($fiscalValidation['reason']);
        }

        // Log fiscal override if applicable
        if (isset($fiscalValidation['override']) && $fiscalValidation['override']) {
            $this->fiscalValidator->logOverride(
                $fiscalValidation['period'],
                auth()->user(),
                "Posted OCR document {$document->uuid}"
            );
        }

        // Validate dimensions
        if ($document->dimensions) {
            $this->dimensionValidator->validateOrFail($document->dimensions);
        }

        // NO automatic currency conversion - keep amounts in original currency
        // Conversion will only happen if explicitly requested by the user
        $exchangeRate = 1.0;
        $needsConversion = false;

        DB::beginTransaction();

        try {
            // Build journal entry lines (NO currency conversion)
            $rawLines = $this->buildExpenseLines($document, $exchangeRate);

            // Validate balance
            $this->validateBalance($rawLines);

            // Convert raw lines to PostingRequestLine DTOs
            $postingLines = array_map(fn (array $line) => new PostingRequestLine(
                accountMappingKey: 'general_expense', // fallback key, overridden by accountId
                accountId: $line['account_id'],
                debit: (float) $line['debit'],
                credit: (float) $line['credit'],
                description: $line['description'] ?? null,
                reference: $line['reference'] ?? null,
                departmentId: $line['dimensions']['department_id'] ?? null,
                projectId: $line['dimensions']['project_id'] ?? null,
                locationId: $line['dimensions']['location_id'] ?? null,
            ), $rawLines);

            // Post via PostingEngine (handles lock-date + fiscal validation)
            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'ocr_document',
                documentId: $document->id,
                postingDate: $document->document_date->toDateString(),
                description: "OCR Document: {$document->vendor_name} - {$document->file_name}",
                lines: $postingLines,
                reference: $document->document_reference ?? "OCR-{$document->uuid}",
                userId: TenantUserHelper::getTenantUserId(),
                legalEntityId: $document->legal_entity_id ?? request()->attributes->get('legal_entity_id'),
                entityBookId: $document->entity_book_id ?? request()->attributes->get('entity_book_id'),
            ));

            if (! $result->success) {
                throw new \Exception($result->error);
            }

            $journalEntry = $result->journalEntry;

            // Update inventory if applicable
            $this->updateInventory($document, $journalEntry);

            // Mark document as posted
            $document->markAsPosted($journalEntry);

            // Dispatch BI update job (if exists)
            $this->dispatchBIUpdate($journalEntry);

            DB::commit();

            Log::info('OCR document posted successfully', [
                'document_uuid' => $document->uuid,
                'journal_entry_id' => $journalEntry->id,
                'total_amount' => $document->total_amount,
                'currency' => $document->currency_code,
            ]);

            return $journalEntry;

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to post OCR document', [
                'document_uuid' => $document->uuid,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Build ledger lines for expense document
     *
     * @param  float  $exchangeRate  Exchange rate to base currency
     */
    private function buildExpenseLines(OcrDocument $document, float $exchangeRate = 1.0): array
    {
        $lines = [];

        // Detect if line items are tax-inclusive
        $lineItemsTotal = 0;
        if ($document->line_items && is_array($document->line_items)) {
            foreach ($document->line_items as $item) {
                $lineItemsTotal += floatval($item['amount'] ?? 0);
            }
        }

        $totalAmount = floatval($document->total_amount ?? 0);
        $taxAmount = floatval($document->tax_amount ?? 0);
        $taxRate = floatval($document->tax_rate ?? 0);

        // If line items sum equals total amount, they are tax-inclusive
        $isTaxInclusive = abs($lineItemsTotal - $totalAmount) < 0.01 && $taxAmount > 0;

        // Calculate tax rate if not provided (derive from amounts)
        if ($isTaxInclusive && $taxRate == 0 && $taxAmount > 0) {
            // Tax rate = tax / (total - tax) * 100
            $taxExclusiveTotal = $totalAmount - $taxAmount;
            if ($taxExclusiveTotal > 0) {
                $taxRate = ($taxAmount / $taxExclusiveTotal) * 100;
            }
        }

        // Debit: Expense accounts (from line items)
        if ($document->line_items && is_array($document->line_items)) {
            foreach ($document->line_items as $item) {
                if (! isset($item['account_id']) || ! isset($item['amount'])) {
                    throw new \Exception('Line item missing account_id or amount');
                }

                $itemAmount = floatval($item['amount']);

                // If tax-inclusive, calculate tax-exclusive amount
                if ($isTaxInclusive && $taxRate > 0) {
                    $itemAmount = $itemAmount / (1 + $taxRate / 100);
                }

                // Apply exchange rate conversion
                $itemAmount = round($itemAmount * $exchangeRate, 2);

                // Merge document-level and line-level dimensions
                $dimensions = $this->mergeDimensions(
                    $document->dimensions,
                    $item['dimensions'] ?? null
                );

                $lines[] = [
                    'account_id' => $item['account_id'],
                    'debit' => $itemAmount,
                    'credit' => 0,
                    'description' => $item['description'] ?? 'Expense',
                    'reference' => $item['sku'] ?? null,
                    'dimensions' => $dimensions,
                ];
            }
        } else {
            // Fallback: Single expense line if no line items
            $expenseAccount = $this->getDefaultExpenseAccount();

            $lines[] = [
                'account_id' => $expenseAccount->id,
                'debit' => $document->subtotal ?? $document->total_amount,
                'credit' => 0,
                'description' => "Expense - {$document->vendor_name}",
                'reference' => $document->document_reference,
                'dimensions' => $document->dimensions,
            ];
        }

        // Debit: Input Tax account (for purchases, tax paid is a recoverable asset)
        if ($document->tax_amount && $document->tax_amount > 0) {
            $taxAccount = $this->getTaxAccount();

            $lines[] = [
                'account_id' => $taxAccount->id,
                'debit' => round($document->tax_amount * $exchangeRate, 2),
                'credit' => 0,
                'description' => 'Input Tax',
                'reference' => $document->document_reference,
                'dimensions' => $document->dimensions,
            ];
        }

        // Credit: Accounts Payable
        $apAccount = $this->getAccountsPayableAccount();

        $lines[] = [
            'account_id' => $apAccount->id,
            'debit' => 0,
            'credit' => round($document->total_amount * $exchangeRate, 2),
            'description' => "AP - {$document->vendor_name}",
            'reference' => $document->document_reference,
            'dimensions' => $document->dimensions,
        ];

        return $lines;
    }

    /**
     * Merge document-level and line-level dimensions
     * Line-level dimensions override document-level
     */
    private function mergeDimensions(?array $documentDimensions, ?array $lineDimensions): ?array
    {
        if (! $documentDimensions && ! $lineDimensions) {
            return null;
        }

        $merged = $documentDimensions ?? [];

        if ($lineDimensions) {
            // Line dimensions override document dimensions
            $merged = array_merge($merged, $lineDimensions);
        }

        return empty($merged) ? null : $merged;
    }

    /**
     * Validate that debits equal credits
     *
     * @throws \Exception
     */
    private function validateBalance(array $lines): void
    {
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($lines as $line) {
            $totalDebit += $line['debit'];
            $totalCredit += $line['credit'];
        }

        $difference = abs($totalDebit - $totalCredit);

        if ($difference > 0.01) {
            throw new \Exception(
                "Unbalanced journal entry: Debits ({$totalDebit}) != Credits ({$totalCredit}), Difference: {$difference}"
            );
        }
    }

    /**
     * Update inventory for matched SKUs
     */
    private function updateInventory(OcrDocument $document, JournalEntry $journalEntry): void
    {
        if (! $document->line_items) {
            return;
        }

        foreach ($document->line_items as $item) {
            if (! isset($item['sku']) || ! $item['sku']) {
                continue;
            }

            // Try to match SKU to product
            $product = $this->findProductBySku($item['sku']);

            if (! $product) {
                Log::warning('SKU not found in inventory', [
                    'sku' => $item['sku'],
                    'document_uuid' => $document->uuid,
                    'journal_entry_id' => $journalEntry->id,
                ]);

                continue;
            }

            // Validate product matches expected SKU or barcode
            $resolvedViaBarcode = $product->sku !== $item['sku'];
            if ($resolvedViaBarcode && $product->barcode !== $item['sku']) {
                Log::warning('Product resolution mismatch - input does not match resolved product SKU or barcode', [
                    'sku' => $item['sku'],
                    'resolved_product_id' => $product->id,
                    'resolved_sku' => $product->sku,
                    'resolved_barcode' => $product->barcode,
                ]);
                // Continue processing but log for audit - this is a warning, not a block
            }

            // Only track inventory for products with track_inventory enabled
            if (! $product->track_inventory) {
                Log::info('Product found but inventory tracking disabled', [
                    'sku' => $item['sku'],
                    'product_id' => $product->id,
                    'document_uuid' => $document->uuid,
                ]);

                continue;
            }

            // Create stock movement (IN for purchases)
            $quantity = max(0, (int) ($item['quantity'] ?? 1));
            if ($quantity <= 0) {
                continue;
            }

            $unitCost = isset($item['amount']) && $quantity > 0
                ? $item['amount'] / $quantity
                : $product->cost;

            $this->createStockMovement(
                $product,
                $quantity,
                $unitCost,
                $document,
                $journalEntry,
                $item
            );

            Log::info('Stock movement created for OCR document', [
                'sku' => $item['sku'],
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'document_uuid' => $document->uuid,
                'journal_entry_id' => $journalEntry->id,
            ]);
        }
    }

    /**
     * Find product by SKU
     */
    private function findProductBySku(string $sku): ?\App\Models\Product
    {
        // Try exact match first
        $product = \App\Models\Product::where('sku', $sku)
            ->where('is_active', true)
            ->first();

        if ($product) {
            return $product;
        }

        // Try barcode match as fallback
        $product = \App\Models\Product::where('barcode', $sku)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();

        // Log barcode fallback usage for audit trail
        if ($product) {
            Log::warning('Product resolved via barcode fallback', [
                'sku_input' => $sku,
                'product_id' => $product->id,
                'product_sku' => $product->sku,
                'product_barcode' => $product->barcode,
            ]);
        }

        return $product;
    }

    /**
     * Create stock movement and update WAC
     */
    private function createStockMovement(
        \App\Models\Product $product,
        int $quantity,
        float $unitCost,
        OcrDocument $document,
        JournalEntry $journalEntry,
        array $item
    ): void {
        $warehouseId = $this->resolveWarehouseIdForItem($item);

        // Calculate new WAC before updating stock
        $oldQuantity = $product->stock_quantity;
        $oldCost = $product->cost;
        $newQuantity = $oldQuantity + $quantity;

        // Weighted Average Cost formula:
        // New WAC = (Old Quantity × Old Cost + New Quantity × New Cost) / Total Quantity
        if ($newQuantity > 0) {
            $newWac = (($oldQuantity * $oldCost) + ($quantity * $unitCost)) / $newQuantity;
            $product->cost = round($newWac, 2);
            $product->save();
        }

        $this->inventoryService->adjustStock(
            productId: $product->id,
            warehouseId: $warehouseId,
            quantity: $quantity,
            type: 'purchase',
            reference: "OCR-{$document->uuid}",
            referenceType: 'ocr_document',
            referenceId: $document->id,
            notes: "Inventory receipt from OCR document: {$document->vendor_name} - {$document->document_reference}"
        );

        $product->refresh();

        Log::info('WAC updated for product', [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $product->stock_quantity,
            'old_cost' => $oldCost,
            'new_cost' => $product->cost,
            'unit_cost' => $unitCost,
        ]);
    }

    /**
     * Resolve the warehouse used for OCR inventory posting.
     */
    private function resolveWarehouseIdForItem(array $item): int
    {
        $lineWarehouseId = isset($item['warehouse_id']) ? (int) $item['warehouse_id'] : null;

        if ($lineWarehouseId) {
            $lineWarehouse = Warehouse::where('id', $lineWarehouseId)
                ->where('is_active', true)
                ->first();

            if (! $lineWarehouse) {
                throw new \InvalidArgumentException("OCR line item warehouse_id {$lineWarehouseId} is invalid or inactive.");
            }

            return (int) $lineWarehouse->id;
        }

        $defaultWarehouse = Warehouse::where('is_default', true)
            ->where('is_active', true)
            ->first();

        if (! $defaultWarehouse) {
            throw new \RuntimeException('Cannot post OCR inventory movement: no active default warehouse and no line-item warehouse_id.');
        }

        return (int) $defaultWarehouse->id;
    }

    /**
     * Dispatch BI update job
     */
    private function dispatchBIUpdate(JournalEntry $journalEntry): void
    {
        // The observer will handle this automatically when the entry is posted
        // But we can also dispatch directly here for immediate updates

        try {
            $date = \Carbon\Carbon::parse($journalEntry->entry_date);
            $tenantId = function_exists('tenant') && tenant() ? (string) tenant()->getTenantKey() : null;

            if (! $tenantId) {
                throw new \RuntimeException('Unable to dispatch BI update job without tenant context.');
            }

            \Modules\OCRBookkeeping\Jobs\UpdateDailyLedgerSummary::dispatch($date, $tenantId)
                ->onQueue('bi-updates')
                ->delay(now()->addSeconds(30)); // Delay to batch multiple updates

            Log::info('BI update job dispatched', [
                'journal_entry_id' => $journalEntry->id,
                'entry_date' => $date->format('Y-m-d'),
                'tenant_id' => $tenantId,
            ]);

        } catch (\Exception $e) {
            Log::warning('Failed to dispatch BI update job', [
                'journal_entry_id' => $journalEntry->id,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - BI update failure shouldn't block posting
        }
    }

    /**
     * Get default expense account
     *
     * @throws \Exception
     */
    private function getDefaultExpenseAccount(): ChartOfAccount
    {
        return $this->accountMapper->resolve('general_expense', 'ocr_document');
    }

    /**
     * Get tax account
     *
     * @throws \Exception
     */
    private function getTaxAccount(): ChartOfAccount
    {
        return $this->accountMapper->resolve('input_tax', 'ocr_document');
    }

    /**
     * Get accounts payable account
     *
     * @throws \Exception
     */
    private function getAccountsPayableAccount(): ChartOfAccount
    {
        return $this->accountMapper->resolve('accounts_payable', 'ocr_document');
    }

    /**
     * Batch post multiple documents
     *
     * @return array ['posted' => array, 'failed' => array]
     */
    public function batchPost(array $documentUuids): array
    {
        $posted = [];
        $failed = [];

        foreach ($documentUuids as $uuid) {
            try {
                $document = OcrDocument::where('uuid', $uuid)->firstOrFail();
                $journalEntry = $this->postDocument($document);

                $posted[] = [
                    'uuid' => $uuid,
                    'journal_entry_id' => $journalEntry->id,
                    'entry_number' => $journalEntry->entry_number,
                ];

            } catch (\Exception $e) {
                $failed[] = [
                    'uuid' => $uuid,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [
            'posted' => $posted,
            'failed' => $failed,
            'total_posted' => count($posted),
            'total_failed' => count($failed),
        ];
    }

    /**
     * Add exchange gain/loss entry if rounding differences exist
     */
    private function addExchangeGainLoss(array $lines, OcrDocument $document, float $exchangeRate): array
    {
        // Calculate total debits and credits
        $totalDebits = array_sum(array_column(array_filter($lines, fn ($l) => $l['debit'] > 0), 'debit'));
        $totalCredits = array_sum(array_column(array_filter($lines, fn ($l) => $l['credit'] > 0), 'credit'));

        $difference = round($totalDebits - $totalCredits, 2);

        // If there's a rounding difference due to exchange rate conversion
        if (abs($difference) >= 0.01) {
            $exchangeAccount = $this->getExchangeGainLossAccount();

            if ($difference > 0) {
                // Debits > Credits: Exchange Loss (credit to balance)
                $lines[] = [
                    'account_id' => $exchangeAccount->id,
                    'debit' => 0,
                    'credit' => abs($difference),
                    'description' => 'Exchange Loss',
                    'reference' => $document->document_reference,
                    'dimensions' => null,
                ];
            } else {
                // Credits > Debits: Exchange Gain (debit to balance)
                $lines[] = [
                    'account_id' => $exchangeAccount->id,
                    'debit' => abs($difference),
                    'credit' => 0,
                    'description' => 'Exchange Gain',
                    'reference' => $document->document_reference,
                    'dimensions' => null,
                ];
            }

            Log::info('Exchange gain/loss entry added', [
                'difference' => $difference,
                'type' => $difference > 0 ? 'loss' : 'gain',
            ]);
        }

        return $lines;
    }

    /**
     * Store original currency amounts for audit trail
     */
    private function storeOriginalCurrencyAmounts(JournalEntry $journalEntry, OcrDocument $document, float $exchangeRate): void
    {
        // Store in journal entry metadata or separate table
        // For now, we'll add it to the journal entry description
        $originalCurrencyInfo = sprintf(
            ' [Original: %s %s @ rate %s]',
            $document->currency_code,
            number_format($document->total_amount, 2),
            number_format($exchangeRate, 4)
        );

        $journalEntry->update([
            'description' => $journalEntry->description.$originalCurrencyInfo,
        ]);

        Log::info('Original currency amounts stored', [
            'journal_entry_id' => $journalEntry->id,
            'original_currency' => $document->currency_code,
            'original_amount' => $document->total_amount,
            'exchange_rate' => $exchangeRate,
        ]);
    }

    /**
     * Get exchange gain/loss account
     *
     * @throws \Exception
     */
    private function getExchangeGainLossAccount(): ChartOfAccount
    {
        // Try to find exchange gain/loss account (typically 7xxx or 8xxx)
        $account = ChartOfAccount::where('code', 'LIKE', '7%')
            ->where('name', 'LIKE', '%exchange%')
            ->first();

        if (! $account) {
            // Fallback to other income/expense account
            $account = ChartOfAccount::where('code', '7000')->first();
        }

        if (! $account) {
            throw new \Exception('Exchange gain/loss account not found. Please configure account code 7000 or similar.');
        }

        return $account;
    }

    /**
     * Reverse the GL entry for an OCR document (FIX-03 WS-08).
     *
     * Extracted from OcrDocument::unpost() so that the PostingEngine is never
     * called directly from an Eloquent model. The model delegates here, keeping
     * all GL mutation behind PostingEngine in the service layer.
     *
     * @throws \Exception on reversal failure
     */
    public function unpostDocument(OcrDocument $document, int $actorUserId): void
    {
        DB::transaction(function () use ($document, $actorUserId) {
            $document->refresh();

            if (! $document->isPosted()) {
                throw new \Exception("Cannot unpost document '{$document->uuid}': current status is '{$document->status}'.");
            }

            if (empty($document->journal_entry_id)) {
                throw new \Exception("Cannot unpost document '{$document->uuid}': missing journal entry reference.");
            }

            $journalEntry = JournalEntry::find($document->journal_entry_id);

            if (! $journalEntry) {
                throw new \Exception("Cannot unpost document '{$document->uuid}': journal entry '{$document->journal_entry_id}' was not found.");
            }

            $result = $this->postingEngine->reverse(new ReversalRequest(
                journalEntryId: (int) $document->journal_entry_id,
                reversalDate: now()->toDateString(),
                reason: "Unpost OCR document {$document->uuid}",
                reasonCode: 'OCR_UNPOST',
                actorUserId: $actorUserId,
            ));

            if (! $result->success) {
                throw new \Exception("Failed to reverse journal entry: {$result->error}");
            }

            $document->update([
                'status' => 'NEEDS_REVIEW',
                'journal_entry_id' => null,
                'posted_at' => null,
                'posted_by' => null,
            ]);
        });
    }
}
