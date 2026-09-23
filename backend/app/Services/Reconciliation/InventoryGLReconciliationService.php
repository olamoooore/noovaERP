<?php

namespace App\Services\Reconciliation;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\ReconciliationControlResult;
use App\Models\ReconciliationException;
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
    public function reconcile(string $asOfDate, ?int $glAccountId = null, float $tolerance = 0.01, bool $persist = true): array
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

        $result = [
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

        // Hostile re-review (#109): the rewrite dropped the persisted
        // ReconciliationControlResult / ReconciliationException evidence the
        // AR/AP siblings keep. Persist it again (opt-out for pure reads).
        if ($persist) {
            $this->persistResult($result);
        }

        return $result;
    }

    /**
     * Persist the control result (and open exceptions for drift) so the
     * reconciliation is auditable like ARGL/APGL.
     */
    private function persistResult(array $result): ReconciliationControlResult
    {
        $control = ReconciliationControlResult::create([
            'control_name' => 'inventory_gl_reconciliation',
            'as_of_date' => $result['as_of_date'],
            'subledger_balance' => $result['inventory_valuation'],
            'gl_balance' => $result['gl_inventory_balance'],
            'difference' => $result['variance'],
            'status' => $result['within_tolerance'] ? 'passed' : 'failed',
            'discrepancies' => $result['drift_items'],
            'executed_by' => \Modules\OCRBookkeeping\Helpers\TenantUserHelper::resolveTenantUserId(),
        ]);

        if (! $result['within_tolerance']) {
            foreach ($result['drift_items'] as $item) {
                $reference = $item['sku'] ?? null;

                if (! $reference) {
                    continue;
                }

                $exists = ReconciliationException::where('reference', $reference)
                    ->whereIn('status', [ReconciliationException::STATUS_OPEN, ReconciliationException::STATUS_IN_PROGRESS])
                    ->exists();

                if ($exists) {
                    continue;
                }

                ReconciliationException::create([
                    'exception_type' => ReconciliationException::TYPE_TIMING_DIFFERENCE,
                    'amount' => $item['cost_value'] ?? null,
                    'reference' => $reference,
                    'description' => 'Inventory valuation differs from the GL control account',
                    'priority' => ReconciliationException::PRIORITY_MEDIUM,
                    'status' => ReconciliationException::STATUS_OPEN,
                    'aging_bucket' => ReconciliationException::BUCKET_CURRENT,
                    'days_open' => 0,
                    'reconciliation_control_result_id' => $control->id,
                ]);
            }
        }

        return $control;
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
