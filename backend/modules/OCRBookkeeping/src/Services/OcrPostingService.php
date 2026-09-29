<?php

namespace Modules\OCRBookkeeping\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\ReversalRequest;
use App\Accounting\Services\AccountMappingService;
use App\Models\JournalEntry;
use App\Models\ChartOfAccount;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\FiscalPeriodValidator;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Inventory\Services\InventoryService;

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
     * @param OcrDocument $document
     * @return JournalEntry
     * @throws \Exception
     */
    public function postDocument(OcrDocument $document): JournalEntry
    {
        // P1-5: route OCR posting through the canonical Bill flow.
        // The whole operation is wrapped in a DB transaction so that a failure
        // leaves the OCR document in its original state (status, journal_entry_id,
        // posted_at) rather than partially promoted to PROMOTED.
        return DB::transaction(function () use ($document) {
            if (! $document->vendor_id) {
                throw new \Exception('OCR document has no vendor_id; cannot post.');
            }

            $this->validateDocumentTotals($document);
            $this->logFiscalPeriodOverrideIfLocked($document);

            // Promote to canonical Bill (idempotent on already-promoted docs)
            if ($document->status !== 'PROMOTED' || ! $document->promoted_bill_id) {
                $promotion = app(\App\Services\OcrPromotionService::class)->promoteToBill($document->id);
                $billId = $promotion['bill_id'];
                $document->refresh();
            } else {
                $billId = $document->promoted_bill_id;
            }

            // Post the canonical bill through the standard PostingEngine flow
            $bill = \App\Models\Bill::findOrFail($billId);
            $vendorBillService = app(\App\Services\VendorBillService::class);
            $postingResult = $vendorBillService->postBill($bill);

            if (! $postingResult->success) {
                throw new \Exception($postingResult->error ?? 'Bill posting failed');
            }

            $journalEntry = JournalEntry::findOrFail($postingResult->journalEntryId);

            // Preserve inventory impact for SKU-matched OCR documents (legacy behavior)
            $this->updateInventory($document, $journalEntry);

            // Mark OCR document as fully posted
            $document->update([
                'status' => 'POSTED',
                'journal_entry_id' => $journalEntry->id,
                'posted_at' => now(),
            ]);

            return $journalEntry;
        });
    }

    /**
     * If the document date falls in a locked fiscal period, attempt to log an
     * override audit record (mirrors the legacy raw-posting path behaviour).
     */
    private function logFiscalPeriodOverrideIfLocked(OcrDocument $document): void
    {
        $period = \App\Models\FiscalPeriod::where('period_start', '<=', $document->document_date)
            ->where('period_end', '>=', $document->document_date)
            ->first();

        if (! $period || $period->status !== 'locked') {
            return;
        }

        $user = auth()->user();
        if (! $user) {
            return;
        }

        $validator = app(\App\Services\FiscalPeriodValidator::class);
        $validator->logOverride($period, $user, 'ocr_document_post');
    }

    /**
     * Validate that OCR line-item totals are consistent with document totals.
     */
    private function validateDocumentTotals(OcrDocument $document): void
    {
        $lineTotal = 0;
        if ($document->line_items && is_array($document->line_items)) {
            foreach ($document->line_items as $item) {
                $quantity = (float) ($item['quantity'] ?? 1);
                $unitPrice = (float) ($item['unit_price'] ?? 0);
                $amount = (float) ($item['amount'] ?? 0);

                if ($unitPrice > 0) {
                    $lineTotal += round($unitPrice * $quantity, 2);
                } else {
                    $lineTotal += $amount;
                }
            }
        }

        $documentTotal = (float) ($document->total_amount ?? 0);
        $taxAmount = (float) ($document->tax_amount ?? 0);
        $expectedTotal = round($lineTotal + $taxAmount, 2);

        if ($documentTotal > 0 && abs($expectedTotal - $documentTotal) > 0.01) {
            throw new \Exception(
                "OCR document line totals + tax ({$expectedTotal}) do not match document total ({$documentTotal})."
            );
        }
    }

    /**
     * Update inventory for matched SKUs
     * 
     * @param OcrDocument $document
     * @param JournalEntry $journalEntry
     * @return void
     */
    private function updateInventory(OcrDocument $document, JournalEntry $journalEntry): void
    {
        if (!$document->line_items) {
            return;
        }

        foreach ($document->line_items as $item) {
            if (!isset($item['sku']) || !$item['sku']) {
                continue;
            }

            // Try to match SKU to product
            $product = $this->findProductBySku($item['sku']);
            
            if (!$product) {
                Log::warning("SKU not found in inventory", [
                    'sku' => $item['sku'],
                    'document_uuid' => $document->uuid,
                    'journal_entry_id' => $journalEntry->id,
                ]);
                continue;
            }

            // Validate product matches expected SKU or barcode
            $resolvedViaBarcode = $product->sku !== $item['sku'];
            if ($resolvedViaBarcode && $product->barcode !== $item['sku']) {
                Log::warning("Product resolution mismatch - input does not match resolved product SKU or barcode", [
                    'sku' => $item['sku'],
                    'resolved_product_id' => $product->id,
                    'resolved_sku' => $product->sku,
                    'resolved_barcode' => $product->barcode,
                ]);
                // Continue processing but log for audit - this is a warning, not a block
            }

            // Only track inventory for products with track_inventory enabled
            if (!$product->track_inventory) {
                Log::info("Product found but inventory tracking disabled", [
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

            Log::info("Stock movement created for OCR document", [
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
     * 
     * @param string $sku
     * @return \App\Models\Product|null
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
            Log::warning("Product resolved via barcode fallback", [
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
     * 
     * @param \App\Models\Product $product
     * @param int $quantity
     * @param float $unitCost
     * @param OcrDocument $document
     * @param JournalEntry $journalEntry
     * @param array $item
     * @return void
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

        Log::info("WAC updated for product", [
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

            if (!$lineWarehouse) {
                throw new \InvalidArgumentException("OCR line item warehouse_id {$lineWarehouseId} is invalid or inactive.");
            }

            return (int) $lineWarehouse->id;
        }

        $defaultWarehouse = Warehouse::where('is_default', true)
            ->where('is_active', true)
            ->first();

        if (!$defaultWarehouse) {
            throw new \RuntimeException('Cannot post OCR inventory movement: no active default warehouse and no line-item warehouse_id.');
        }

        return (int) $defaultWarehouse->id;
    }

    /**
     * Dispatch BI update job
     * 
     * @param JournalEntry $journalEntry
     * @return void
     */
    private function dispatchBIUpdate(JournalEntry $journalEntry): void
    {
        // The observer will handle this automatically when the entry is posted
        // But we can also dispatch directly here for immediate updates
        
        try {
            $date = \Carbon\Carbon::parse($journalEntry->entry_date);
            $tenantId = function_exists('tenant') && tenant() ? (string) tenant()->getTenantKey() : null;

            if (!$tenantId) {
                throw new \RuntimeException('Unable to dispatch BI update job without tenant context.');
            }
            
            \Modules\OCRBookkeeping\Jobs\UpdateDailyLedgerSummary::dispatch($date, $tenantId)
                ->onQueue('bi-updates')
                ->delay(now()->addSeconds(30)); // Delay to batch multiple updates
            
            Log::info("BI update job dispatched", [
                'journal_entry_id' => $journalEntry->id,
                'entry_date' => $date->format('Y-m-d'),
                'tenant_id' => $tenantId,
            ]);
            
        } catch (\Exception $e) {
            Log::warning("Failed to dispatch BI update job", [
                'journal_entry_id' => $journalEntry->id,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - BI update failure shouldn't block posting
        }
    }

    /**
     * Batch post multiple documents
     * 
     * @param array $documentUuids
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
     * 
     * @param array $lines
     * @param OcrDocument $document
     * @param float $exchangeRate
     * @return array
     */
    private function addExchangeGainLoss(array $lines, OcrDocument $document, float $exchangeRate): array
    {
        // Calculate total debits and credits
        $totalDebits = array_sum(array_column(array_filter($lines, fn($l) => $l['debit'] > 0), 'debit'));
        $totalCredits = array_sum(array_column(array_filter($lines, fn($l) => $l['credit'] > 0), 'credit'));
        
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
            
            Log::info("Exchange gain/loss entry added", [
                'difference' => $difference,
                'type' => $difference > 0 ? 'loss' : 'gain',
            ]);
        }
        
        return $lines;
    }

    /**
     * Store original currency amounts for audit trail
     * 
     * @param JournalEntry $journalEntry
     * @param OcrDocument $document
     * @param float $exchangeRate
     * @return void
     */
    private function storeOriginalCurrencyAmounts(JournalEntry $journalEntry, OcrDocument $document, float $exchangeRate): void
    {
        // Store in journal entry metadata or separate table
        // For now, we'll add it to the journal entry description
        $originalCurrencyInfo = sprintf(
            " [Original: %s %s @ rate %s]",
            $document->currency_code,
            number_format($document->total_amount, 2),
            number_format($exchangeRate, 4)
        );
        
        $journalEntry->update([
            'description' => $journalEntry->description . $originalCurrencyInfo
        ]);
        
        Log::info("Original currency amounts stored", [
            'journal_entry_id' => $journalEntry->id,
            'original_currency' => $document->currency_code,
            'original_amount' => $document->total_amount,
            'exchange_rate' => $exchangeRate,
        ]);
    }

    /**
     * Get exchange gain/loss account
     * 
     * @return ChartOfAccount
     * @throws \Exception
     */
    private function getExchangeGainLossAccount(): ChartOfAccount
    {
        // Try to find exchange gain/loss account (typically 7xxx or 8xxx)
        $account = ChartOfAccount::where('code', 'LIKE', '7%')
            ->where('name', 'LIKE', '%exchange%')
            ->first();
            
        if (!$account) {
            // Fallback to other income/expense account
            $account = ChartOfAccount::where('code', '7000')->first();
        }
        
        if (!$account) {
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

            if (!$document->isPosted()) {
                throw new \Exception("Cannot unpost document '{$document->uuid}': current status is '{$document->status}'.");
            }

            if (empty($document->journal_entry_id)) {
                throw new \Exception("Cannot unpost document '{$document->uuid}': missing journal entry reference.");
            }

            $journalEntry = JournalEntry::find($document->journal_entry_id);

            if (!$journalEntry) {
                throw new \Exception("Cannot unpost document '{$document->uuid}': journal entry '{$document->journal_entry_id}' was not found.");
            }

            $result = $this->postingEngine->reverse(new ReversalRequest(
                journalEntryId: (int) $document->journal_entry_id,
                reversalDate:   now()->toDateString(),
                reason:         "Unpost OCR document {$document->uuid}",
                reasonCode:     'OCR_UNPOST',
                actorUserId:    $actorUserId,
            ));

            if (!$result->success) {
                throw new \Exception("Failed to reverse journal entry: {$result->error}");
            }

            // Reverse inventory stock movements
            $this->reverseInventoryForDocument($document);

            $document->update([
                'status'          => 'NEEDS_REVIEW',
                'journal_entry_id' => null,
                'posted_at'       => null,
                'posted_by'       => null,
            ]);
        });
    }

    /**
     * Reverse inventory stock movements created during posting.
     *
     * Finds all stock movements linked to this OCR document and creates
     * opposite (OUT) movements to decrement stock. Product stock_quantity
     * is updated directly and WAC is recalculated from remaining stock.
     *
     * @param OcrDocument $document
     * @return void
     */
    private function reverseInventoryForDocument(OcrDocument $document): void
    {
        $movements = StockMovement::where('reference_type', 'ocr_document')
            ->where('reference_id', $document->id)
            ->get();

        if ($movements->isEmpty()) {
            return;
        }

        foreach ($movements as $movement) {
            $product = \App\Models\Product::find($movement->product_id);
            if (!$product) {
                Log::warning("Inventory reversal: product not found", [
                    'product_id' => $movement->product_id,
                    'movement_id' => $movement->id,
                ]);
                continue;
            }

            // Create reversal movement (OUT to decrement stock)
            $reversalQuantity = $movement->quantity;

            $this->inventoryService->adjustStock(
                productId: $product->id,
                warehouseId: $movement->warehouse_id,
                quantity: $reversalQuantity,
                type: 'transfer_out',
                reference: "OCR-UNPOST-{$document->uuid}",
                referenceType: 'ocr_document_unpost',
                referenceId: $document->id,
                notes: "Inventory reversal from OCR unpost: {$document->document_reference}"
            );

            // Recalculate WAC from current stock state after the OUT movement
            $product->refresh();

            Log::info("Inventory reversed for OCR unpost", [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'reversed_quantity' => $reversalQuantity,
                'original_movement_id' => $movement->id,
                'document_uuid' => $document->uuid,
            ]);
        }
    }

}
