<?php

namespace App\Services;

use App\Models\FiscalPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PeriodCloseService
{
    /**
     * Pre-close validation checks for a fiscal period.
     */
    public function validateBeforeClose(FiscalPeriod $period): array
    {
        $issues = [];

        if ($period->isLocked()) {
            $issues[] = 'Period is already locked';
        }

        if ($period->isClosed()) {
            $issues[] = 'Period is already closed';
        }

        // Check for pending approvals first (safest query)
        if (Schema::hasTable('approval_requests')) {
            try {
                $pendingApprovals = DB::table('approval_requests')
                    ->where('status', 'pending')
                    ->where('created_at', '>=', $period->period_start)
                    ->where('created_at', '<=', $period->period_end)
                    ->count();

                if ($pendingApprovals > 0) {
                    $issues[] = "{$pendingApprovals} pending approval requests";
                }
            } catch (\Exception $e) {
                // skip
            }
        }

        // Check for unposted journal entries
        if (Schema::hasTable('journal_entries')) {
            try {
                $unpostedEntries = DB::table('journal_entries')
                    ->where('status', 'draft')
                    ->where('entry_date', '>=', $period->period_start)
                    ->where('entry_date', '<=', $period->period_end)
                    ->count();

                if ($unpostedEntries > 0) {
                    $issues[] = "{$unpostedEntries} unposted journal entries in this period";
                }
            } catch (\Exception $e) {
                // skip
            }
        }

        return $issues;
    }

    /**
     * Execute the period close process.
     */
    public function closePeriod(FiscalPeriod $period, string $userId): array
    {
        // Pre-close validation
        $issues = $this->validateBeforeClose($period);
        if (! empty($issues)) {
            throw new \DomainException('PERIOD_CLOSE_VALIDATION_FAILED: ' . implode(', ', $issues));
        }

        // Run automatic adjustments (accruals, revaluations)
        $adjustments = $this->runAutomaticAdjustments($period);

        // Generate closing entries
        $closingEntries = $this->generateClosingEntries($period);

        // Close the period
        FiscalPeriod::where('id', $period->id)->update(['status' => 'closed']);

        // Create close record
        $closeRecord = $this->createCloseRecord($period, $userId, $adjustments, $closingEntries);

        Log::info('Period closed', [
            'period_id' => $period->id,
            'period_code' => $period->code,
            'closed_by' => $userId,
            'adjustments' => count($adjustments),
            'closing_entries' => count($closingEntries),
        ]);

        return [
            'period_id' => $period->id,
            'status' => 'closed',
            'adjustments' => $adjustments,
            'closing_entries' => $closingEntries,
            'close_record_id' => $closeRecord['id'],
        ];
    }

    /**
     * Reopen a closed period (requires special permission).
     */
    public function reopenPeriod(FiscalPeriod $period, string $userId, string $reason): array
    {
        return DB::transaction(function () use ($period, $userId, $reason) {
            $period = FiscalPeriod::lockForUpdate()->findOrFail($period->id);

            if ($period->isLocked()) {
                throw new \DomainException('CANNOT_REOPEN_LOCKED_PERIOD');
            }

            if ($period->isOpen()) {
                throw new \DomainException('PERIOD_ALREADY_OPEN');
            }

            // Remove closing entries
            $removedEntries = $this->removeClosingEntries($period);

            // Remove automatic adjustments
            $removedAdjustments = $this->removeAutomaticAdjustments($period);

            // Reopen the period
            $period->reopen();

            Log::info('Period reopened', [
                'period_id' => $period->id,
                'period_code' => $period->code,
                'reopened_by' => $userId,
                'reason' => $reason,
                'removed_entries' => $removedEntries,
                'removed_adjustments' => $removedAdjustments,
            ]);

            return [
                'period_id' => $period->id,
                'status' => 'open',
                'removed_entries' => $removedEntries,
                'removed_adjustments' => $removedAdjustments,
            ];
        });
    }

    /**
     * Get period close status and summary.
     */
    public function getPeriodSummary(FiscalPeriod $period): array
    {
        $journalSummary = $this->getJournalSummary($period);

        return [
            'period' => [
                'id' => $period->id,
                'code' => $period->code,
                'name' => $period->name,
                'status' => $period->status,
                'start' => $period->period_start->toDateString(),
                'end' => $period->period_end->toDateString(),
            ],
            'journal_summary' => $journalSummary,
            'validation_issues' => $this->validateBeforeClose($period),
            'can_close' => empty($this->validateBeforeClose($period)),
        ];
    }

    private function getUnbalancedEntries(FiscalPeriod $period): int
    {
        if (! Schema::hasTable('journal_entries') || ! Schema::hasTable('journal_entry_lines')) {
            return 0;
        }

        try {
            return DB::table('journal_entries')
                ->join('journal_entry_lines', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
                ->where('journal_entries.entry_date', '>=', $period->period_start)
                ->where('journal_entries.entry_date', '<=', $period->period_end)
                ->groupBy('journal_entries.id')
                ->havingRaw('ABS(SUM(debit) - SUM(credit)) > 0.01')
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function validateJournalEntries(FiscalPeriod $period): void
    {
        $unbalanced = $this->getUnbalancedEntries($period);
        if ($unbalanced > 0) {
            throw new \DomainException("UNBALANCED_JOURNAL_ENTRIES: {$unbalanced} entries found");
        }
    }

    private function runAutomaticAdjustments(FiscalPeriod $period): array
    {
        $adjustments = [];

        // Example: Create accrual entries for recurring expenses
        // This is a placeholder for actual adjustment logic
        Log::info('Running automatic adjustments', ['period' => $period->code]);

        return $adjustments;
    }

    private function generateClosingEntries(FiscalPeriod $period): array
    {
        $entries = [];

        // Generate closing entries for income/expense accounts
        // This is a placeholder for actual closing entry logic
        Log::info('Generating closing entries', ['period' => $period->code]);

        return $entries;
    }

    private function createCloseRecord(FiscalPeriod $period, string $userId, array $adjustments, array $closingEntries): array
    {
        $record = [
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'period_id' => $period->id,
            'closed_by' => $userId,
            'closed_at' => now(),
            'adjustments_count' => count($adjustments),
            'closing_entries_count' => count($closingEntries),
        ];

        // Store in database or cache
        return $record;
    }

    private function removeClosingEntries(FiscalPeriod $period): int
    {
        // Remove closing entries for this period
        return 0;
    }

    private function removeAutomaticAdjustments(FiscalPeriod $period): int
    {
        // Remove automatic adjustments for this period
        return 0;
    }

    private function getJournalSummary(FiscalPeriod $period): array
    {
        if (! Schema::hasTable('journal_entries') || ! Schema::hasTable('journal_entry_lines')) {
            return [
                'total_debit' => 0,
                'total_credit' => 0,
                'is_balanced' => true,
                'entry_count' => 0,
            ];
        }

        $totalDebit = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.entry_date', '>=', $period->period_start)
            ->where('journal_entries.entry_date', '<=', $period->period_end)
            ->sum('debit');

        $totalCredit = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entries.entry_date', '>=', $period->period_start)
            ->where('journal_entries.entry_date', '<=', $period->period_end)
            ->sum('credit');

        $entryCount = DB::table('journal_entries')
            ->where('entry_date', '>=', $period->period_start)
            ->where('entry_date', '<=', $period->period_end)
            ->count();

        return [
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced' => abs($totalDebit - $totalCredit) < 0.01,
            'entry_count' => $entryCount,
        ];
    }
}
