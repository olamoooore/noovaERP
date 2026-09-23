<?php

namespace App\Services\Reconciliation;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Services\InventoryService;

/**
 * Issue #109 — no reconciliation existed between the Inventory subledger
 * (quantity x product cost, InventoryService::getInventoryValuation()) and the
 * GL Inventory control account balance. This is the Inventory sibling of
 * ARGLReconciliationService/APGLReconciliationService.
 *
 * The service is deliberately read-only: it computes both sides as of a date
 * and surfaces the variance + per-product detail when outside tolerance.
 */
class InventoryGLReconciliationService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
    ) {}

    /**
     * Reconcile the inventory subledger valuation against the GL Inventory
     * control account as of a date.
     *
     * @return array{as_of_date: string, inventory_valuation: float, gl_inventory_balance: float, variance: float, within_tolerance: bool, tolerance: float, gl_account: array|null, item_count: int, drift_items: array}
     */
    public function reconcile(string $asOfDate, ?int $glAccountId = null, float $tolerance = 0.01): array
    {
        $asOf = Carbon::parse($asOfDate);

        $valuation = $this->inventoryService->getInventoryValuation();
        $inventoryValuation = round((float) $valuation['summary']['total_cost_value'], 2);

        $glAccount = $glAccountId
            ? ChartOfAccount::find($glAccountId)
            : $this->resolveInventoryAccount();

        $glBalance = $glAccount
            ? $this->getGLBalance((int) $glAccount->id, $asOf)
            : 0.0;

        $variance = round($inventoryValuation - $glBalance, 2);
        $withinTolerance = abs($variance) <= $tolerance;

        return [
            'as_of_date' => $asOf->toDateString(),
            'inventory_valuation' => $inventoryValuation,
            'gl_inventory_balance' => $glBalance,
            'variance' => $variance,
            'within_tolerance' => $withinTolerance,
            'tolerance' => $tolerance,
            'gl_account' => $glAccount ? [
                'id' => $glAccount->id,
                'code' => $glAccount->code,
                'name' => $glAccount->name,
            ] : null,
            'item_count' => (int) $valuation['summary']['total_items'],
            // Per-product detail only when the two sides disagree, mirroring
            // the AR/AP discrepancy drill-down.
            'drift_items' => $withinTolerance ? [] : array_map(fn (array $item) => [
                'product_id' => $item['product_id'],
                'product_name' => $item['product_name'],
                'sku' => $item['sku'],
                'quantity' => $item['quantity'],
                'unit_cost' => $item['unit_cost'],
                'cost_value' => $item['cost_value'],
            ], $valuation['items']),
        ];
    }

    /**
     * Resolve the canonical Inventory control account: explicit account
     * mapping first, then the canonical account code.
     */
    private function resolveInventoryAccount(): ?ChartOfAccount
    {
        $mappedId = AccountMapping::query()
            ->where('mapping_key', 'inventory')
            ->value('account_id');

        if ($mappedId) {
            $account = ChartOfAccount::find($mappedId);
            if ($account) {
                return $account;
            }
        }

        return ChartOfAccount::query()->where('code', '1200')->first();
    }

    /**
     * GL balance of the control account as of a date (posted, active versions).
     */
    private function getGLBalance(int $accountId, Carbon $asOf): float
    {
        $balance = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('jel.account_id', $accountId)
            ->where('je.status', 'posted')
            ->where('je.is_active_version', true)
            ->where('je.entry_date', '<=', $asOf->toDateString())
            ->sum(DB::raw('jel.debit - jel.credit'));

        return round((float) $balance, 2);
    }
}
