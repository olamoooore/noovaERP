<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;

/**
 * Issue #92: real Budget Management — CRUD with account-level lines, an
 * approval transition, and a variance engine that compares stored budget
 * amounts against actual posted GL activity for the budget period.
 */
class BudgetService
{
    public function create(array $data): Budget
    {
        return DB::transaction(function () use ($data) {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $data['status'] = $data['status'] ?? 'draft';
            $data['version'] = $data['version'] ?? 1;

            $budget = Budget::create($data);

            $this->replaceLines($budget, $lines);

            return $budget->load('lines.account');
        });
    }

    public function update(Budget $budget, array $data): Budget
    {
        if ($budget->isApproved() && ! ($data['allow_approved_edit'] ?? false)) {
            throw new \RuntimeException('Approved budgets cannot be edited. Create a new version instead.');
        }

        return DB::transaction(function () use ($budget, $data) {
            $lines = $data['lines'] ?? null;
            unset($data['lines'], $data['allow_approved_edit']);

            $budget->update($data);

            if (is_array($lines)) {
                $this->replaceLines($budget, $lines);
            }

            return $budget->fresh(['lines.account']);
        });
    }

    public function approve(Budget $budget, ?int $userId = null): Budget
    {
        if ($budget->isApproved()) {
            throw new \RuntimeException('Budget is already approved.');
        }

        if ($budget->lines()->count() === 0) {
            throw new \RuntimeException('A budget with no lines cannot be approved.');
        }

        $budget->update([
            'status' => 'approved',
            'approved_by' => $userId,
            'approved_at' => now(),
        ]);

        return $budget->fresh(['lines.account']);
    }

    /**
     * Budget vs actual for every budget line, from posted GL activity inside
     * the budget period. Actual = sum(debit - credit) for the account (the
     * natural expense-side balance).
     */
    public function variance(Budget $budget): array
    {
        $budget->loadMissing('lines.account');

        $accountIds = $budget->lines->pluck('account_id')->unique()->values();

        $actuals = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->whereIn('jel.account_id', $accountIds->all())
            ->where('je.status', 'posted')
            ->where('je.is_active_version', true)
            ->whereBetween('je.entry_date', [$budget->period_start->toDateString(), $budget->period_end->toDateString()])
            ->when($budget->legal_entity_id, fn ($query) => $query->where('je.legal_entity_id', $budget->legal_entity_id))
            ->groupBy('jel.account_id')
            ->selectRaw('jel.account_id, SUM(jel.debit - jel.credit) as actual_amount')
            ->pluck('actual_amount', 'jel.account_id');

        $lines = $budget->lines->map(function (BudgetLine $line) use ($actuals) {
            $budgetAmount = round((float) $line->amount, 2);
            $actualAmount = round((float) ($actuals[$line->account_id] ?? 0), 2);
            $variance = round($budgetAmount - $actualAmount, 2);

            return [
                'account_id' => $line->account_id,
                'account_code' => $line->account?->code,
                'account_name' => $line->account?->name,
                'cost_center_id' => $line->cost_center_id,
                'budget_amount' => $budgetAmount,
                'actual_amount' => $actualAmount,
                'variance' => $variance,
                'variance_pct' => $budgetAmount != 0.0
                    ? round(($variance / $budgetAmount) * 100, 1)
                    : null,
            ];
        })->values()->all();

        $totalBudget = round(array_sum(array_column($lines, 'budget_amount')), 2);
        $totalActual = round(array_sum(array_column($lines, 'actual_amount')), 2);

        return [
            'budget' => [
                'id' => $budget->id,
                'name' => $budget->name,
                'fiscal_year' => $budget->fiscal_year,
                'status' => $budget->status,
                'version' => $budget->version,
                'period_start' => $budget->period_start->toDateString(),
                'period_end' => $budget->period_end->toDateString(),
            ],
            'lines' => $lines,
            'totals' => [
                'budget_amount' => $totalBudget,
                'actual_amount' => $totalActual,
                'variance' => round($totalBudget - $totalActual, 2),
            ],
        ];
    }

    private function replaceLines(Budget $budget, array $lines): void
    {
        $budget->lines()->delete();

        foreach ($lines as $line) {
            if (! isset($line['account_id'])) {
                continue;
            }

            // Only real accounts can carry a budget line.
            if (! ChartOfAccount::whereKey($line['account_id'])->exists()) {
                throw new \InvalidArgumentException("Unknown account id {$line['account_id']} in budget line.");
            }

            BudgetLine::create([
                'budget_id' => $budget->id,
                'account_id' => $line['account_id'],
                'cost_center_id' => $line['cost_center_id'] ?? null,
                'amount' => round((float) ($line['amount'] ?? 0), 2),
                'notes' => $line['notes'] ?? null,
            ]);
        }
    }
}
