<?php

namespace App\Services\Reconciliation;

use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use App\Models\ReconciliationControlResult;
use App\Models\ReconciliationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Modules\Inventory\Models\StockLevel;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * InventoryGLReconciliationService
 * 
 * Provides inventory-to-GL reconciliation to verify that stock value in the
 * stock_levels table matches the GL inventory account balance.
 */
class InventoryGLReconciliationService
{
    /**
     * Run inventory to GL reconciliation control
     * 
     * @param string $asOfDate Date to reconcile as of (YYYY-MM-DD)
     * @param int|null $controlAccountId Optional specific inventory control account
     * @return ReconciliationControlResult
     */
    public function reconcile(string $asOfDate, ?int $controlAccountId = null): ReconciliationControlResult
    {
        $asOfDate = Carbon::parse($asOfDate);
        
        // Get inventory control account if not specified
        if (!$controlAccountId) {
            $controlAccount = $this->getInventoryControlAccount();
            $controlAccountId = $controlAccount?->id;
        }
        
        if (!$controlAccountId) {
            throw new \Exception('Inventory control account not found');
        }
        
        // Get stock subledger value
        $subledgerValue = $this->getStockValue($asOfDate);
        
        // Get GL inventory account balance
        $glBalance = $this->getGLBalance($controlAccountId, $asOfDate);
        
        // Calculate difference
        $difference = round($subledgerValue - $glBalance, 2);
        
        // Identify discrepancies if any
        $discrepancies = [];
        if (abs($difference) > 0.01) {
            $discrepancies = $this->identifyDiscrepancies($asOfDate, $controlAccountId);
        }
        
        // Create control result record
        $result = ReconciliationControlResult::create([
            'control_name' => 'inventory_gl_reconciliation',
            'as_of_date' => $asOfDate->toDateString(),
            'subledger_balance' => $subledgerValue,
            'gl_balance' => $glBalance,
            'difference' => $difference,
            'status' => abs($difference) < 0.01 ? 'passed' : 'failed',
            'discrepancies' => $discrepancies,
            'executed_by' => TenantUserHelper::requireTenantUserId(),
            'notes' => null,
        ]);

        // Automatically create reconciliation exceptions for each discrepancy
        if ($result->status === 'failed' && !empty($discrepancies)) {
            $this->createExceptionsFromDiscrepancies($result, $discrepancies);
        }
        
        Log::info('Inventory to GL reconciliation completed', [
            'as_of_date' => $asOfDate->toDateString(),
            'subledger_value' => $subledgerValue,
            'gl_balance' => $glBalance,
            'difference' => $difference,
            'status' => $result->status
        ]);
        
        return $result;
    }
    
    /**
     * Get inventory control account from chart of accounts.
     *
     * Resolution order:
     *   1. account_mappings table (mapping_key = 'inventory')
     *   2. COA code '1200' (seeded default for Inventory)
     *   3. Name-based fallback
     */
    private function getInventoryControlAccount(): ?ChartOfAccount
    {
        $mapping = \App\Models\AccountMapping::where('mapping_key', 'inventory')
            ->whereNull('document_type')
            ->where('is_active', true)
            ->first();

        if ($mapping) {
            return $mapping->account;
        }

        return ChartOfAccount::where('code', '1200')
            ->orWhere('name', 'like', '%Inventory%')
            ->first();
    }
    
    /**
     * Get total stock value as of date
     * 
     * Stock value = SUM(quantity * cost) across all products and warehouses
     */
    private function getStockValue(Carbon $asOfDate): float
    {
        return StockLevel::join('products', 'stock_levels.product_id', '=', 'products.id')
            ->where('products.status', '!=', 'discontinued')
            ->selectRaw('COALESCE(SUM(stock_levels.quantity * products.cost), 0) as value')
            ->value('value') ?? 0.0;
    }
    
    /**
     * Get GL inventory account balance as of date
     */
    private function getGLBalance(int $controlAccountId, Carbon $asOfDate): float
    {
        return JournalEntryLine::where('account_id', $controlAccountId)
            ->whereHas('journalEntry', function ($q) use ($asOfDate) {
                $q->where('entry_date', '<=', $asOfDate)
                  ->where('status', 'posted');
            })
            ->selectRaw('SUM(debit - credit) as balance')
            ->value('balance') ?? 0.0;
    }
    
    /**
     * Identify specific discrepancies between stock and GL
     */
    private function identifyDiscrepancies(Carbon $asOfDate, int $controlAccountId): array
    {
        $discrepancies = [];
        
        // 1. Find products with stock but no GL movement
        $stockWithoutGL = $this->findStockWithoutGL($asOfDate);
        if (!empty($stockWithoutGL)) {
            $totalValue = array_sum(array_column($stockWithoutGL, 'stock_value'));
            $discrepancies[] = [
                'type' => 'stock_without_gl',
                'description' => 'Stock exists but no corresponding GL entry',
                'count' => count($stockWithoutGL),
                'total_value' => $totalValue,
                'items' => $stockWithoutGL,
            ];
        }
        
        // 2. Find GL entries without stock record
        $glWithoutStock = $this->findGLEntriesWithoutStock($asOfDate, $controlAccountId);
        if (!empty($glWithoutStock)) {
            $discrepancies[] = [
                'type' => 'gl_without_stock',
                'description' => 'GL entries without corresponding stock record',
                'count' => count($glWithoutStock),
                'items' => $glWithoutStock,
            ];
        }
        
        // 3. Find value mismatches (stock value != GL value per product)
        $valueMismatches = $this->findValueMismatches($asOfDate, $controlAccountId);
        if (!empty($valueMismatches)) {
            $discrepancies[] = [
                'type' => 'value_mismatch',
                'description' => 'Stock value differs from GL value per product',
                'count' => count($valueMismatches),
                'items' => $valueMismatches,
            ];
        }
        
        return $discrepancies;
    }
    
    /**
     * Find products with stock but no GL movement
     */
    private function findStockWithoutGL(Carbon $asOfDate): array
    {
        return DB::table('stock_levels as sl')
            ->join('products as p', 'sl.product_id', '=', 'p.id')
            ->leftJoin('journal_entry_lines as jel', function ($join) {
                $join->on('jel.reference', '=', 'p.sku')
                    ->where('jel.account_id', '=', function ($q) {
                        $q->select('id')
                            ->from('chart_of_accounts')
                            ->where('code', '1200')
                            ->orWhere('name', 'like', '%Inventory%')
                            ->limit(1);
                    });
            })
            ->whereNull('jel.id')
            ->where('sl.quantity', '>', 0)
            ->where('p.status', '!=', 'discontinued')
            ->select(
                'p.id',
                'p.sku',
                'p.name',
                'sl.quantity',
                'p.cost',
                DB::raw('sl.quantity * p.cost as stock_value')
            )
            ->limit(100)
            ->get()
            ->toArray();
    }
    
    /**
     * Find GL entries without stock record
     */
    private function findGLEntriesWithoutStock(Carbon $asOfDate, int $controlAccountId): array
    {
        return JournalEntryLine::where('account_id', $controlAccountId)
            ->whereHas('journalEntry', function ($q) use ($asOfDate) {
                $q->where('entry_date', '<=', $asOfDate)
                  ->where('status', 'posted');
            })
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('products')
                    ->whereColumn('products.sku', '=', 'journal_entry_lines.reference');
            })
            ->join('journal_entries as je', 'je.id', '=', 'journal_entry_lines.journal_entry_id')
            ->select(
                'journal_entry_lines.id',
                'journal_entry_lines.journal_entry_id',
                'journal_entry_lines.reference',
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
                'je.entry_date'
            )
            ->limit(100)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'journal_entry_id' => $row->journal_entry_id,
                'reference' => $row->reference,
                'amount' => (float) $row->debit - (float) $row->credit,
                'entry_date' => $row->entry_date,
            ])
            ->toArray();
    }
    
    /**
     * Find products where stock value differs from GL value
     */
    private function findValueMismatches(Carbon $asOfDate, int $controlAccountId): array
    {
        $glValues = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('products as p', 'p.sku', '=', 'jel.reference')
            ->where('jel.account_id', $controlAccountId)
            ->where('je.entry_date', '<=', $asOfDate)
            ->where('je.status', 'posted')
            ->groupBy('p.id', 'p.sku', 'p.name', 'p.cost')
            ->select(
                'p.id',
                'p.sku',
                'p.name',
                'p.cost',
                DB::raw('SUM(jel.debit - jel.credit) as gl_value')
            )
            ->havingRaw('ABS(SUM(jel.debit - jel.credit) - (p.cost * COALESCE((SELECT SUM(sl.quantity) FROM stock_levels sl WHERE sl.product_id = p.id), 0)) > 0.01')
            ->limit(100)
            ->get()
            ->toArray();
        
        // Enrich with stock values
        $result = [];
        foreach ($glValues as $item) {
            $stockQty = DB::table('stock_levels')
                ->where('product_id', $item->id)
                ->sum('quantity');
            $stockValue = $stockQty * $item->cost;
            
            $result[] = [
                'id' => $item->id,
                'sku' => $item->sku,
                'name' => $item->name,
                'cost' => $item->cost,
                'stock_quantity' => $stockQty,
                'stock_value' => $stockValue,
                'gl_value' => $item->gl_value,
                'difference' => $stockValue - $item->gl_value,
            ];
        }
        
        return $result;
    }
    
    /**
     * Map discrepancy type to exception type
     */
    private function mapDiscrepancyType(string $discrepancyType): string
    {
        return match ($discrepancyType) {
            'stock_without_gl' => ReconciliationException::TYPE_MISSING_RECEIPT,
            'gl_without_stock' => ReconciliationException::TYPE_TIMING_DIFFERENCE,
            'value_mismatch' => ReconciliationException::TYPE_UNMATCHED_TRANSACTION,
            default => ReconciliationException::TYPE_UNMATCHED_TRANSACTION,
        };
    }

    /**
     * Create reconciliation exceptions from discrepancies
     * 
     * Idempotent: only creates exceptions for items that don't already have an open exception
     */
    private function createExceptionsFromDiscrepancies(ReconciliationControlResult $result, array $discrepancies): void
    {
        foreach ($discrepancies as $discrepancy) {
            $exceptionType = $this->mapDiscrepancyType($discrepancy['type']);
            
            foreach ($discrepancy['items'] ?? [] as $item) {
                $reference = $item['sku'] ?? $item['reference'] ?? null;
                
                // Skip if no reference to track
                if (!$reference) {
                    continue;
                }
                
                // Idempotent: check if an open exception already exists for this reference
                $existingException = ReconciliationException::where('reference', $reference)
                    ->whereIn('status', [ReconciliationException::STATUS_OPEN, ReconciliationException::STATUS_IN_PROGRESS])
                    ->first();
                
                if ($existingException) {
                    continue;
                }
                
                ReconciliationException::create([
                    'exception_type' => $exceptionType,
                    'amount' => $item['stock_value'] ?? $item['total_value'] ?? $item['gl_value'] ?? null,
                    'transaction_date' => $item['entry_date'] ?? null,
                    'reference' => $reference,
                    'description' => $discrepancy['description'],
                    'priority' => ReconciliationException::PRIORITY_MEDIUM,
                    'status' => ReconciliationException::STATUS_OPEN,
                    'aging_bucket' => ReconciliationException::BUCKET_CURRENT,
                    'days_open' => 0,
                    'reconciliation_control_result_id' => $result->id,
                ]);
            }
        }
        
        Log::info('Inventory reconciliation exceptions created', [
            'control_result_id' => $result->id,
            'discrepancy_count' => count($discrepancies),
        ]);
    }
    
    /**
     * Get reconciliation history
     */
    public function getHistory(int $limit = 12): array
    {
        return ReconciliationControlResult::where('control_name', 'inventory_gl_reconciliation')
            ->orderByDesc('as_of_date')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}