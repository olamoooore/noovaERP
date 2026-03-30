<?php

namespace Modules\FinancialReporting\Services;

use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\DTOs\ReportData;
use Modules\FinancialReporting\Services\Reports\ProfitLossService;
use Modules\FinancialReporting\Services\Reports\BalanceSheetService;
use Modules\FinancialReporting\Services\Reports\CashFlowService;
use Illuminate\Support\Facades\DB;

/**
 * Generates consolidated financial reports across all entities in a group.
 *
 * Steps:
 *  1. Run per-entity report.
 *  2. Sum entity totals.
 *  3. Subtract intercompany elimination entries.
 */
class ConsolidatedReportService
{
    public function __construct(
        protected ProfitLossService $plService,
        protected BalanceSheetService $bsService,
        protected CashFlowService $cfService,
    ) {}

    /**
     * Consolidated P&L for a group.
     */
    public function profitAndLoss(int $groupId, ReportFilter $baseFilter): ReportData
    {
        $entities = $this->getGroupEntities($groupId);

        $combinedRevenue = 0;
        $combinedCogs = 0;
        $combinedOpex = 0;
        $combinedOtherIncome = 0;
        $combinedOtherExpenses = 0;
        $entityBreakdown = [];

        foreach ($entities as $entity) {
            $filter = clone $baseFilter;
            $filter->legalEntityId = $entity->id;
            $report = $this->plService->generate($filter);

            $combinedRevenue += $report->data['revenue']['total'];
            $combinedCogs += $report->data['cost_of_goods_sold']['total'];
            $combinedOpex += $report->data['operating_expenses']['total'];
            $combinedOtherIncome += $report->data['other_income']['total'];
            $combinedOtherExpenses += $report->data['other_expenses']['total'];

            $entityBreakdown[] = [
                'entity_id' => $entity->id,
                'entity_name' => $entity->name,
                'net_profit' => $report->data['net_profit']['amount'],
            ];
        }

        // Intercompany eliminations
        $icEliminations = $this->getIntercompanyEliminations($groupId, $baseFilter);

        $grossProfit = $combinedRevenue - $combinedCogs;
        $operatingProfit = $grossProfit - $combinedOpex;
        $netProfit = $operatingProfit + $combinedOtherIncome - $combinedOtherExpenses - $icEliminations['total'];

        return new ReportData(
            title: 'Consolidated Profit & Loss Statement',
            data: [
                'revenue' => ['total' => $combinedRevenue],
                'cost_of_goods_sold' => ['total' => $combinedCogs],
                'gross_profit' => ['amount' => $grossProfit],
                'operating_expenses' => ['total' => $combinedOpex],
                'operating_profit' => ['amount' => $operatingProfit],
                'other_income' => ['total' => $combinedOtherIncome],
                'other_expenses' => ['total' => $combinedOtherExpenses],
                'intercompany_eliminations' => $icEliminations,
                'net_profit' => ['amount' => $netProfit],
                'entity_breakdown' => $entityBreakdown,
            ],
            summary: [
                'revenue' => $combinedRevenue,
                'net_profit' => $netProfit,
                'entity_count' => count($entities),
            ],
            metadata: [
                'group_id' => $groupId,
                'generated_at' => now()->toIso8601String(),
                'type' => 'consolidated',
            ],
        );
    }

    /**
     * Consolidated Balance Sheet for a group.
     */
    public function balanceSheet(int $groupId, ReportFilter $baseFilter): ReportData
    {
        $entities = $this->getGroupEntities($groupId);

        $totalAssets = 0;
        $totalLiabilities = 0;
        $totalEquity = 0;
        $entityBreakdown = [];

        foreach ($entities as $entity) {
            $filter = clone $baseFilter;
            $filter->legalEntityId = $entity->id;
            $report = $this->bsService->generate($filter);

            $totalAssets += $report->data['assets']['total'];
            $totalLiabilities += $report->data['liabilities']['total'];
            $totalEquity += $report->data['equity']['total'];

            $entityBreakdown[] = [
                'entity_id' => $entity->id,
                'entity_name' => $entity->name,
                'total_assets' => $report->data['assets']['total'],
            ];
        }

        // Eliminate intercompany balances (DUE_FROM / DUE_TO net to zero)
        $icBalances = $this->getIntercompanyBalances($groupId, $baseFilter);
        $totalAssets -= $icBalances;
        $totalLiabilities -= $icBalances;

        return new ReportData(
            title: 'Consolidated Balance Sheet',
            data: [
                'assets' => ['total' => $totalAssets],
                'liabilities' => ['total' => $totalLiabilities],
                'equity' => ['total' => $totalEquity],
                'total_liabilities_and_equity' => $totalLiabilities + $totalEquity,
                'intercompany_elimination' => $icBalances,
                'entity_breakdown' => $entityBreakdown,
            ],
            summary: [
                'total_assets' => $totalAssets,
                'total_liabilities' => $totalLiabilities,
                'total_equity' => $totalEquity,
                'entity_count' => count($entities),
            ],
            metadata: [
                'group_id' => $groupId,
                'as_of_date' => $baseFilter->asOfDate?->format('Y-m-d'),
                'generated_at' => now()->toIso8601String(),
                'type' => 'consolidated',
            ],
        );
    }

    // ── Helpers ─────────────────────────────────────────

    protected function getGroupEntities(int $groupId): array
    {
        return DB::table('legal_entities')
            ->where('group_id', $groupId)
            ->where('status', 'active')
            ->get()
            ->all();
    }

    /**
     * Sum intercompany P&L eliminations (revenue/expense between group entities).
     */
    protected function getIntercompanyEliminations(int $groupId, ReportFilter $filter): array
    {
        $entityIds = DB::table('legal_entities')
            ->where('group_id', $groupId)
            ->where('status', 'active')
            ->pluck('id');

        if ($entityIds->isEmpty()) {
            return ['total' => 0, 'items' => []];
        }

        // Intercompany entries are those posted by IntercompanyPostingService
        // where both source and target entities are within the same group.
        // They reference matching DUE_FROM / DUE_TO accounts.
        $items = DB::table('journal_entries as je')
            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')
            ->where('je.reference', 'like', 'IC-%')
            ->whereIn('je.legal_entity_id', $entityIds)
            ->whereBetween('je.entry_date', [$filter->startDate, $filter->endDate])
            ->select([
                'je.legal_entity_id',
                DB::raw('SUM(jel.debit) as total_debit'),
                DB::raw('SUM(jel.credit) as total_credit'),
            ])
            ->groupBy('je.legal_entity_id')
            ->get()
            ->toArray();

        $total = collect($items)->sum(fn($i) => $i->total_debit);

        return ['total' => $total, 'items' => $items];
    }

    /**
     * Get intercompany balances to eliminate from BS (DUE_FROM assets = DUE_TO liabilities).
     */
    protected function getIntercompanyBalances(int $groupId, ReportFilter $filter): float
    {
        $entityIds = DB::table('legal_entities')
            ->where('group_id', $groupId)
            ->where('status', 'active')
            ->pluck('id');

        if ($entityIds->isEmpty()) {
            return 0;
        }

        // Sum DUE_FROM balances (which should equal DUE_TO balances within the group)
        $dueFromAccountIds = DB::table('entity_account_roles as ear')
            ->join('account_roles as ar', 'ar.id', '=', 'ear.account_role_id')
            ->where('ar.code', 'INTERCOMPANY_DUE_FROM')
            ->where('ear.is_active', true)
            ->whereIn('ear.legal_entity_id', $entityIds)
            ->pluck('ear.chart_of_account_id');

        if ($dueFromAccountIds->isEmpty()) {
            return 0;
        }

        return DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'jel.journal_entry_id', '=', 'je.id')
            ->where('je.status', 'posted')
            ->whereIn('je.legal_entity_id', $entityIds)
            ->whereIn('jel.account_id', $dueFromAccountIds)
            ->where('je.entry_date', '<=', $filter->asOfDate ?? $filter->endDate)
            ->sum(DB::raw('jel.debit - jel.credit'));
    }
}
