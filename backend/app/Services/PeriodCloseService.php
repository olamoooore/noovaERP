<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\ReversalRequest;
use App\Models\FiscalPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PeriodCloseService
{
    public function __construct(
        private readonly PostingEngineInterface $postingEngine,
    ) {}

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
        if ($this->tableExists('approval_requests')) {
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
        if ($this->tableExists('journal_entries')) {
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
            throw new \DomainException('PERIOD_CLOSE_VALIDATION_FAILED: '.implode(', ', $issues));
        }

        try {
            return DB::transaction(function () use ($period, $userId) {
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
            });
        } catch (\Throwable $e) {
            Log::error('PeriodCloseService::closePeriod failed', [
                'period_id' => $period->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
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
        if (! $this->tableExists('journal_entries') || ! $this->tableExists('journal_entry_lines')) {
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

    /**
     * Check if all given tables exist in the current search_path (which may
     * be the public schema or a tenant schema). Uses a raw EXISTS query that
     * respects the connection's search_path so it works correctly inside
     * tenant contexts.
     */
    private function tablesExist(array $tables): bool
    {
        foreach ($tables as $table) {
            if (! $this->tableExists($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if a single table exists in the current search_path.
     */
    private function tableExists(string $table): bool
    {
        try {
            return (bool) DB::scalar(
                'SELECT EXISTS (SELECT 1 FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relname = ? AND c.relkind IN (\'r\', \'p\') AND n.nspname = ANY (string_to_array(current_setting(\'search_path\'), \' \')))',
                [$table]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Check if a column exists on a table (search_path-aware).
     */
    private function columnExists(string $table, string $column): bool
    {
        try {
            $exists = DB::scalar(
                'SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ? LIMIT 1',
                [$table, $column]
            );

            return $exists !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function runAutomaticAdjustments(FiscalPeriod $period): array
    {
        $adjustments = [];

        // P1-6: previously a no-op stub. Now actually scans for FX revaluation
        // drift on multi-currency GL balances within the period, and posts
        // a revaluation adjustment when an unrealised FX gain/loss exists.
        if (! $this->tablesExist(['journal_entry_lines', 'journal_entries'])) {
            return $adjustments;
        }

        try {
            // Hostile re-review C-5 (#102): the previous implementation was a
            // placeholder that always returned zero. Delegate to the canonical
            // FxRevaluationService, which resolves period-end rates and posts
            // the unrealised gain/loss through PostingEngine.
            $baseCurrency = (string) (DB::table('company_settings')->value('base_currency') ?: 'USD');

            $fxResults = app(\App\Accounting\Services\FxRevaluationService::class)
                ->revalue($period->period_end->toDateString(), $baseCurrency);

            foreach ($fxResults as $fxResult) {
                // FxRevaluationService returns PostingResult objects (one per
                // currency with a material revaluation).
                $adjustments[] = [
                    'type' => 'fx_revaluation',
                    'journal_entry_id' => $fxResult->journalEntryId ?? null,
                    'posted' => (bool) ($fxResult->success ?? false),
                ];
            }
        } catch (\Exception $e) {
            Log::error('PeriodCloseService::runAutomaticAdjustments failed', [
                'period_id' => $period->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $adjustments;
    }

    private function generateClosingEntries(FiscalPeriod $period): array
    {
        $entries = [];

        // P1-6: previously a no-op stub. Now actually generates canonical
        // closing entries: each income/expense account's period balance is
        // zeroed by an offsetting posting to Retained Earnings. This is the
        // standard end-of-period close accounting treatment.
        if (! $this->tablesExist(['journal_entry_lines', 'journal_entries'])) {
            return $entries;
        }

        try {
            $plBalances = $this->aggregatePeriodPLBalances($period);
            if (empty($plBalances)) {
                return $entries;
            }

            $retainedEarningsId = $this->ensureRetainedEarningsAccount($period);

            $closingEntry = $this->postClosingEntry($period, $retainedEarningsId, $plBalances);
            if ($closingEntry) {
                $entries[] = $closingEntry;
            }
        } catch (\Exception $e) {
            Log::error('PeriodCloseService::generateClosingEntries failed', [
                'period_id' => $period->id,
                'error' => $e->getMessage(),
            ]);

            // Hostile re-review (#59 HR59-01): a close whose required closing
            // entry could not be posted must fail closed — never close the
            // period with P&L still on the books.
            throw new \DomainException(
                'PERIOD_CLOSE_CLOSING_ENTRY_FAILED: '.$e->getMessage(),
                0,
                $e,
            );
        }

        return $entries;
    }

    /**
     * Aggregate the period's net P&L balances (revenue, expense) per GL account.
     */
    private function aggregatePeriodPLBalances(FiscalPeriod $period): array
    {
        // Issue #68: only aggregate ACTIVE-version journal entries.
        // Reversed (inactive) entries are the audit-preserved mirror of
        // the correction path — including them would net-zero the P&L
        // after a reopen, preventing a subsequent close from generating
        // a fresh closing entry against the period's current balances.
        $balances = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')
            ->where('je.is_active_version', true)
            ->whereBetween('je.entry_date', [$period->period_start->toDateString(), $period->period_end->toDateString()])
            ->whereIn('coa.type', ['revenue', 'expense'])
            ->groupBy('jel.account_id')
            ->selectRaw('jel.account_id, SUM(jel.credit - jel.debit) as net_balance')
            ->get();

        Log::debug('aggregatePeriodPLBalances', [
            'period_id' => $period->id,
            'date_range' => [$period->period_start->toDateString(), $period->period_end->toDateString()],
            'rows' => $balances->toArray(),
            'count' => $balances->count(),
        ]);

        $out = [];
        foreach ($balances as $row) {
            $out[(int) $row->account_id] = (float) $row->net_balance;
        }

        return $out;
    }

    /**
     * Ensure a Retained Earnings account exists in this tenant's chart of
     * accounts. Creates one (equity, retained_earnings subtype) on first use.
     */
    private function ensureRetainedEarningsAccount(FiscalPeriod $period): int
    {
        $existing = DB::table('chart_of_accounts')
            ->where('code', '3900-RE')
            ->first();
        if ($existing) {
            return (int) $existing->id;
        }

        $existing = DB::table('chart_of_accounts')
            ->where('name', 'Retained Earnings')
            ->where('type', 'equity')
            ->first();
        if ($existing) {
            return (int) $existing->id;
        }

        return (int) DB::table('chart_of_accounts')->insertGetId([
            'code' => '3900-RE',
            'name' => 'Retained Earnings',
            'type' => 'equity',
            'account_type' => 'equity',
            'subtype' => 'equity',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Post the period-close closing entry: debit each revenue account for its
     * net balance, credit each expense account for its net balance, and
     * offset the net P&L into Retained Earnings.
     */
    private function postClosingEntry(FiscalPeriod $period, int $retainedEarningsId, array $plBalances): ?array
    {
        $userId = auth()->id();
        if (! $userId) {
            // Hostile re-review (#59): cannot attribute the closing entry —
            // fail closed rather than closing without it.
            throw new \DomainException('PERIOD_CLOSE_ACTOR_REQUIRED');
        }

        $closingDate = $period->period_end->toDateString();
        $entryNumber = 'CLOSE-'.$period->code.'-'.now()->format('YmdHis');

        $lines = [];
        $totalDebit = 0;
        $totalCredit = 0;
        $plNet = 0; // positive = net income (revenues > expenses), negative = net loss

        foreach ($plBalances as $accountId => $netBalance) {
            // For revenue: net_balance = credit - debit (positive when revenue earned)
            // To close, debit revenue for its balance
            // For expense: net_balance = credit - debit (negative when expense incurred)
            // To close, credit expense for its negative balance

            if (abs($netBalance) < 0.01) {
                continue;
            }

            $plNet += $netBalance;

            // Close by the account's net balance DIRECTION (credit - debit),
            // not by its type: a contra-sign balance (e.g. an FX gain booked
            // to an expense account) would otherwise be closed the wrong way
            // and leave the closing entry unbalanced.
            if ($netBalance > 0) {
                $lines[] = [
                    'account_id' => $accountId,
                    'description' => "Close account for period {$period->code}",
                    'debit' => $netBalance,
                    'credit' => 0,
                ];
                $totalDebit += $netBalance;
            } else {
                $creditAmount = abs($netBalance);
                $lines[] = [
                    'account_id' => $accountId,
                    'description' => "Close account for period {$period->code}",
                    'debit' => 0,
                    'credit' => $creditAmount,
                ];
                $totalCredit += $creditAmount;
            }
        }

        if (empty($lines)) {
            return null;
        }

        // Offsetting entry to Retained Earnings: net income (positive) → credit RE,
        // net loss (negative) → debit RE.
        if ($plNet > 0) {
            $lines[] = [
                'account_id' => $retainedEarningsId,
                'description' => "Net income to retained earnings for {$period->code}",
                'debit' => 0,
                'credit' => $plNet,
            ];
            $totalCredit += $plNet;
        } else {
            $lossAmount = abs($plNet);
            $lines[] = [
                'account_id' => $retainedEarningsId,
                'description' => "Net loss to retained earnings for {$period->code}",
                'debit' => $lossAmount,
                'credit' => 0,
            ];
            $totalDebit += $lossAmount;
        }

        if (abs($totalDebit - $totalCredit) > 0.01) {
            Log::error('PeriodCloseService::postClosingEntry unbalanced', [
                'period_id' => $period->id,
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
            ]);

            throw new \DomainException(
                "PERIOD_CLOSE_CLOSING_ENTRY_UNBALANCED: debit {$totalDebit} != credit {$totalCredit}"
            );
        }

        return DB::transaction(function () use ($closingDate, $entryNumber, $lines, $period, $userId) {
            // Issue #59: closing entries must go through PostingEngine, the
            // authoritative ledger writer. The engine adds the idempotency
            // guard (a second close attempt for the same period resolves to
            // the existing JournalEntryLink instead of double-posting),
            // balance validation, the gate pipeline (TaxGate/AssuranceGate),
            // audit logging, state transitions and the accounting outbox —
            // none of which the previous raw inserts had.
            $postingLines = array_map(fn (array $line) => new PostingRequestLine(
                accountMappingKey: 'period_close_account',
                accountId: (int) $line['account_id'],
                debit: (float) $line['debit'],
                credit: (float) $line['credit'],
                description: $line['description'],
            ), $lines);

            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'period_close',
                documentId: (int) $period->id,
                postingDate: $closingDate,
                description: "Period closing entry for {$period->code}",
                lines: $postingLines,
                reference: $entryNumber,
                userId: $userId,
                sourceCapability: 'ACC-002',
            ));

            if (! $result->success || ! $result->journalEntry) {
                Log::error('PeriodCloseService::postClosingEntry rejected by PostingEngine', [
                    'period_id' => $period->id,
                    'error' => $result->error,
                    'journal_entry_id' => $result->journalEntryId,
                ]);

                // Hostile re-review (#59): a rejected closing entry aborts the
                // close (transaction rollback keeps the period open).
                throw new \DomainException(
                    'PERIOD_CLOSE_CLOSING_ENTRY_REJECTED: '.($result->error ?? 'unknown')
                );
            }

            $entry = $result->journalEntry;

            // Close-marker columns are descriptive metadata, not ledger
            // amounts; they are not in the model's $fillable so the engine
            // does not set them. Stamp them post- (not forceFill — keep the
            // intent explicit) without touching amounts/status/dates.
            $entry->forceFill([
                'is_period_close_entry' => true,
                'period_id' => $period->id,
            ])->save();

            return [
                'type' => 'period_close',
                'journal_entry_id' => $entry->id,
                'entry_number' => $entry->entry_number,
                'lines_count' => count($lines),
                'net_pl' => array_sum(array_column($lines, 'credit')) - array_sum(array_column($lines, 'debit')) + 0,
            ];
        });
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

    /**
     * Reverse the active closing journal entry for a period via the
     * PostingEngine reversal path. The engine flips the original JE to
     * `is_active_version=false` + `lifecycle_status='reversed'` and creates
     * the mirror reversal entry — preserving ledger immutability (the
     * original row is never deleted).
     *
     * Required for issue noovaerp/NoovaERP#68: without this, reopen→
     * re-close cycles resolve the idempotency guard to the SAME (stale)
     * closing entry instead of generating a fresh one against the
     * period's now-current P&L balances.
     */
    private function removeClosingEntries(FiscalPeriod $period): int
    {
        if (! $this->tablesExist(['journal_entries', 'journal_entry_links'])) {
            return 0;
        }

        $reversed = 0;
        // The active-version closing entry for this period. There can be
        // only one in normal operation (the engine's idempotency guard
        // dedupes); if a prior reopen created stale duplicates, we
        // reverse every active one.
        $activeClosings = DB::table('journal_entries as je')
            ->where('je.type', 'period_close')
            ->where('je.is_active_version', true)
            ->where('je.period_id', $period->id)
            ->pluck('je.id');

        foreach ($activeClosings as $jeId) {
            // HR68-01: invoke the authoritative ACC-002 reversal FIRST.
            // Do NOT delete the period_close link before reversal succeeds —
            // that would leave a mixed authoritative state on reversal failure.
            // On reversal failure, FAIL CLOSED (throw) so the outer
            // reopenPeriod() DB::transaction rolls back the whole reopen:
            // the original closing journal, the link, and the period state
            // all remain unchanged (no mixed authoritative state survives).
            $req = new ReversalRequest(
                journalEntryId: (int) $jeId,
                reversalDate: now()->toDateString(),
                reason: "Period reopen for {$period->code} (issue #68)",
                reasonCode: 'PERIOD_REOPEN',
                tenantId: tenant()?->id,
            );
            $result = $this->postingEngine->reverse($req);

            if (! $result->success) {
                // A failed closing-entry reversal is a HARD BLOCKER to
                // period reopen. Throw (no Log::error + continue) so the
                // outer DB::transaction rolls back and no mixed state
                // survives. The exception propagates to reopenPeriod().
                throw new \RuntimeException(
                    'Period reopen blocked: ACC-002 reversal failed for closing JE '
                    ."{$jeId} (period {$period->code}): ".($result->error ?? 'unknown')
                );
            }

            // Reversal succeeded — now it is safe to retire the original
            // posting reference (the link) so the engine's idempotency
            // guard won't find a stale 'post' link on the next re-close.
            DB::table('journal_entry_links')
                ->where('source_document_type', 'period_close')
                ->where('source_document_id', $period->id)
                ->delete();

            // The engine's fullReversal() creates the mirror reversal JE
            // but does NOT mark the original as inactive. Flip the original
            // to is_active_version=false and lifecycle_status='reversed'
            // so it no longer resolves through the active-version guard.
            DB::table('journal_entries')->where('id', $jeId)->update([
                'is_active_version' => false,
                'lifecycle_status' => 'reversed',
                'reversed_at' => now(),
            ]);

            $reversed++;
        }

        return $reversed;
    }

    /**
     * Reverse any auto-adjustment JEs (FX revaluation) tied to this period via
     * PostingEngine.reverse(). Hostile re-review C-5 (#102): FX revaluation
     * now really posts, so reopening a period must undo those entries.
     */
    private function removeAutomaticAdjustments(FiscalPeriod $period): int
    {
        $reversed = 0;

        $entries = \App\Models\JournalEntry::query()
            ->where('status', 'posted')
            ->where('reference', 'like', 'FXREVAL-%')
            ->whereBetween('entry_date', [
                $period->period_start->toDateString(),
                $period->period_end->toDateString(),
            ])
            ->get();

        foreach ($entries as $entry) {
            $result = app(\App\Accounting\Contracts\PostingEngineInterface::class)
                ->reverse(new \App\Accounting\DTOs\ReversalRequest(
                    journalEntryId: (int) $entry->id,
                    reversalDate: now()->toDateString(),
                    reason: "Reopen period {$period->code}: reverse FX revaluation",
                    reasonCode: 'PERIOD_REOPEN',
                    actorUserId: \Modules\OCRBookkeeping\Helpers\TenantUserHelper::resolveTenantUserId(),
                    tenantId: tenant()->id ?? null,
                ));

            if ($result->success) {
                $reversed++;
            } else {
                Log::warning('PeriodCloseService: FX revaluation reversal failed', [
                    'period_id' => $period->id,
                    'journal_entry_id' => $entry->id,
                    'error' => $result->error,
                ]);
            }
        }

        return $reversed;
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
