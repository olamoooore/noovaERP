<?php

namespace Modules\OCRBookkeeping\Services;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\ChartOfAccount;
use App\Services\FiscalPeriodValidator;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
        private ExchangeRateService $exchangeRateService
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
        // Validate document is ready for posting
        if (!$document->canBePosted()) {
            throw new \Exception('Document is not ready to be posted');
        }

        // Validate fiscal period
        $fiscalValidation = $this->fiscalValidator->validateDate($document->document_date);
        if (!$fiscalValidation['allowed']) {
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
            $lines = $this->buildExpenseLines($document, $exchangeRate);

            // NO exchange gain/loss entries needed (no conversion)

            // Validate balance
            $this->validateBalance($lines);

            // Create journal entry
            $journalEntry = $this->createJournalEntry($document, $lines);

            // NO currency amount storage needed (keeping original currency)

            // Update inventory if applicable
            $this->updateInventory($document, $journalEntry);

            // Mark document as posted
            $document->markAsPosted($journalEntry);

            // Dispatch BI update job (if exists)
            $this->dispatchBIUpdate($journalEntry);

            DB::commit();

            Log::info("OCR document posted successfully", [
                'document_uuid' => $document->uuid,
                'journal_entry_id' => $journalEntry->id,
                'total_amount' => $document->total_amount,
                'currency' => $document->currency_code,
            ]);

            return $journalEntry;

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error("Failed to post OCR document", [
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
     * @param OcrDocument $document
     * @param float $exchangeRate Exchange rate to base currency
     * @return array
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
                if (!isset($item['account_id']) || !isset($item['amount'])) {
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

        // Credit: Tax account (Sales Tax Payable is a liability)
        if ($document->tax_amount && $document->tax_amount > 0) {
            $taxAccount = $this->getTaxAccount();
            
            $lines[] = [
                'account_id' => $taxAccount->id,
                'debit' => 0,
                'credit' => round($document->tax_amount * $exchangeRate, 2),
                'description' => 'Tax',
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
     * 
     * @param array|null $documentDimensions
     * @param array|null $lineDimensions
     * @return array|null
     */
    private function mergeDimensions(?array $documentDimensions, ?array $lineDimensions): ?array
    {
        if (!$documentDimensions && !$lineDimensions) {
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
     * @param array $lines
     * @return void
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
     * Create journal entry with lines
     * 
     * @param OcrDocument $document
     * @param array $lines
     * @return JournalEntry
     */
    private function createJournalEntry(OcrDocument $document, array $lines): JournalEntry
    {
        // Generate entry number
        $entryNumber = $this->generateEntryNumber();

        // Create journal entry header
        $journalEntry = JournalEntry::create([
            'entry_number' => $entryNumber,
            'entry_date' => $document->document_date,
            'type' => 'invoice', // Valid types: manual, invoice, payment, adjustment, opening
            'reference' => $document->document_reference ?? "OCR-{$document->uuid}",
            'description' => "OCR Document: {$document->vendor_name} - {$document->file_name}",
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]);

        // Create journal entry lines
        foreach ($lines as $line) {
            JournalEntryLine::create([
                'journal_entry_id' => $journalEntry->id,
                'account_id' => $line['account_id'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'description' => $line['description'],
                'reference' => $line['reference'],
                'dimensions' => $line['dimensions'],
            ]);
        }

        // Post the journal entry
        $journalEntry->post();

        return $journalEntry;
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
            $quantity = $item['quantity'] ?? 1;
            $unitCost = isset($item['amount']) && $quantity > 0 
                ? $item['amount'] / $quantity 
                : $product->cost;

            $this->createStockMovement(
                $product,
                $quantity,
                $unitCost,
                $document,
                $journalEntry
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
            ->first();

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
     * @return void
     */
    private function createStockMovement(
        \App\Models\Product $product,
        int $quantity,
        float $unitCost,
        OcrDocument $document,
        JournalEntry $journalEntry
    ): void {
        // Calculate new WAC before updating stock
        $oldQuantity = $product->stock_quantity;
        $oldCost = $product->cost;
        $newQuantity = $oldQuantity + $quantity;
        
        // Weighted Average Cost formula:
        // New WAC = (Old Quantity × Old Cost + New Quantity × New Cost) / Total Quantity
        if ($newQuantity > 0) {
            $newWac = (($oldQuantity * $oldCost) + ($quantity * $unitCost)) / $newQuantity;
            $product->cost = round($newWac, 2);
        }

        // Update stock quantity
        $product->stock_quantity = $newQuantity;
        $product->save();

        // Create stock movement record
        \App\Models\StockMovement::create([
            'product_id' => $product->id,
            'type' => 'in', // Purchase increases inventory
            'quantity' => $quantity,
            'reference' => "OCR-{$document->uuid}",
            'reference_type' => 'ocr_document',
            'reference_id' => $document->id,
            'notes' => "Inventory receipt from OCR document: {$document->vendor_name} - {$document->document_reference}",
            'balance_after' => $newQuantity,
            'created_by' => $document->posted_by ?? auth()->id(),
        ]);

        Log::info("WAC updated for product", [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
            'old_cost' => $oldCost,
            'new_cost' => $product->cost,
            'unit_cost' => $unitCost,
        ]);
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
            
            \Modules\OCRBookkeeping\Jobs\UpdateDailyLedgerSummary::dispatch($date)
                ->onQueue('bi-updates')
                ->delay(now()->addSeconds(30)); // Delay to batch multiple updates
            
            Log::info("BI update job dispatched", [
                'journal_entry_id' => $journalEntry->id,
                'entry_date' => $date->format('Y-m-d'),
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
     * Get default expense account
     * 
     * @return ChartOfAccount
     * @throws \Exception
     */
    private function getDefaultExpenseAccount(): ChartOfAccount
    {
        $account = ChartOfAccount::where('code', '5000')
            ->where('is_active', true)
            ->first();

        if (!$account) {
            throw new \Exception('Default expense account (5000) not found');
        }

        return $account;
    }

    /**
     * Get tax account
     * 
     * @return ChartOfAccount
     * @throws \Exception
     */
    private function getTaxAccount(): ChartOfAccount
    {
        // For purchases, we should use Input Tax / Tax Recoverable account (asset)
        // Try to find Input Tax account first (typically 1xxx for assets)
        $account = ChartOfAccount::where('code', 'LIKE', '1%')
            ->where(function($query) {
                $query->where('name', 'LIKE', '%input%tax%')
                      ->orWhere('name', 'LIKE', '%tax%recoverable%')
                      ->orWhere('name', 'LIKE', '%tax%receivable%');
            })
            ->where('is_active', true)
            ->first();

        if (!$account) {
            // Fallback to Sales Tax Payable (2200) if no input tax account exists
            // Note: This is not ideal but maintains backward compatibility
            $account = ChartOfAccount::where('code', '2200')
                ->where('is_active', true)
                ->first();
        }

        if (!$account) {
            throw new \Exception('Tax account not found. Please configure an Input Tax account (1xxx) or Sales Tax Payable account (2200)');
        }

        return $account;
    }

    /**
     * Get accounts payable account
     * 
     * @return ChartOfAccount
     * @throws \Exception
     */
    private function getAccountsPayableAccount(): ChartOfAccount
    {
        $account = ChartOfAccount::where('code', '2100')
            ->where('is_active', true)
            ->first();

        if (!$account) {
            throw new \Exception('Accounts Payable account (2100) not found');
        }

        return $account;
    }

    /**
     * Generate unique entry number
     * 
     * @return string
     */
    private function generateEntryNumber(): string
    {
        $prefix = 'JE';
        $date = date('Ymd');
        
        // Get all entries for today and extract sequence numbers
        $entries = JournalEntry::where('entry_number', 'like', "{$prefix}-{$date}-%")
            ->pluck('entry_number')
            ->toArray();
        
        $maxSequence = 0;
        
        foreach ($entries as $entryNumber) {
            // Extract the last 4 digits (sequence number)
            if (preg_match('/(\d{4})$/', $entryNumber, $matches)) {
                $sequence = (int)$matches[1];
                if ($sequence > $maxSequence) {
                    $maxSequence = $sequence;
                }
            }
        }
        
        $count = $maxSequence + 1;
        
        return sprintf('%s-%s-%04d', $prefix, $date, $count);
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

}
