<?php

namespace Modules\FinancialClose\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\AccountReview;
use Modules\FinancialClose\Models\AiFinding;
use Modules\FinancialClose\Models\CloseCycle;

/**
 * AI Analysis Engine for Financial Close
 * Automatically scans accounting data and generates anomaly findings.
 *
 * Detection rules:
 * 1. Large Period-over-Period Variance  — balance changed > 30% vs prior month
 * 2. Negative Asset Balance             — asset account with negative net balance
 * 3. Negative Liability/Equity/Revenue  — liability/equity/revenue with negative balance
 * 4. Inactive Account with Activity     — no activity for 90+ days, then current-period activity
 * 5. Missing Documentation              — large journal lines without description or reference
 * 6. Unposted Journal Entries           — draft entries that should be reviewed
 * 7. Overdue AR Invoices                — customer invoices past due date
 * 8. Unreconciled Bank Transactions     — bank GL lines without reconciliation
 */
class FinancialCloseAiAnalysisService
{
    private const VARIANCE_THRESHOLD = 0.30;   // 30%

    private const LARGE_BALANCE_THRESHOLD = 1000;

    private const INACTIVE_DAYS = 90;

    public function __construct(
        private readonly FinancialCloseAiFindingService $findingService,
        private readonly FinancialCloseBankReconciliationService $bankReconciliationService,
    ) {}

    /**
     * Current analysis month — set by analyze() so helper methods can
     * resolve source references (journal lines, etc.) for findings.
     */
    protected ?Carbon $currentMonth = null;

    /**
     * Run AI analysis for a tenant/entity/period and generate findings.
     * When $refresh is true, clears stale open findings first so resolved issues disappear.
     *
     * @param  string  $periodId  Format YYYY-MM
     * @param  bool  $refresh  If true, delete open findings before re-analyzing
     * @return array Summary of findings created
     */
    public function analyze(string $tenantId, string $entityId, string $periodId, bool $refresh = false): array
    {
        Log::info('FinancialClose AI: Starting analysis', [
            'tenant_id' => $tenantId,
            'entity_id' => $entityId,
            'period_id' => $periodId,
            'refresh' => $refresh,
        ]);

        // On refresh: clear all open findings for this entity so resolved issues disappear
        if ($refresh) {
            $cleared = AiFinding::where('tenant_id', $tenantId)
                ->where('entity_id', $entityId)
                ->where('status', 'open')
                ->update(['status' => 'superseded']);

            Log::info('FinancialClose AI: Cleared stale findings', ['superseded' => $cleared]);
        }

        $currentMonth = $this->resolvePeriodMonth($periodId);
        $this->currentMonth = $currentMonth;
        $priorMonth = $currentMonth->copy()->subMonth();

        $accounts = ChartOfAccount::where('is_active', true)
            ->whereNull('deleted_at')
            ->get();

        $findingsCreated = 0;
        $reviewsCreated = 0;

        foreach ($accounts as $account) {
            // Only analyze posting accounts (L3 leaf nodes) — skip group headers
            if ($account->level < 3) {
                continue;
            }

            $review = $this->ensureReview($tenantId, $entityId, $account);

            $currentBalance = $this->periodBalance($account->id, $currentMonth);
            $priorBalance = $this->periodBalance($account->id, $priorMonth);

            $variance = $priorBalance != 0
                ? ($currentBalance - $priorBalance) / abs($priorBalance)
                : ($currentBalance != 0 ? 1.0 : 0);

            $hasCurrentActivity = $this->hasActivityInPeriod($account->id, $currentMonth);
            $lastActivityDate = $this->lastActivityDate($account->id);

            // Rule 1: Large variance
            if (abs($variance) > self::VARIANCE_THRESHOLD && abs($currentBalance) > self::LARGE_BALANCE_THRESHOLD) {
                $finding = $this->createVarianceFinding($tenantId, $review->id, $entityId, $account, $currentBalance, $priorBalance, $variance);
                if ($finding) {
                    $findingsCreated++;
                }
            }

            // Rule 2: Negative asset balance
            if ($account->type === 'asset' && $currentBalance < 0 && abs($currentBalance) > self::LARGE_BALANCE_THRESHOLD) {
                $finding = $this->createNegativeBalanceFinding($tenantId, $review->id, $entityId, $account, $currentBalance);
                if ($finding) {
                    $findingsCreated++;
                }
            }

            // Rule 3: Negative liability / equity / revenue balance
            if (in_array($account->type, ['liability', 'equity', 'revenue']) && $currentBalance < 0 && abs($currentBalance) > self::LARGE_BALANCE_THRESHOLD) {
                $finding = $this->createNegativeBalanceFinding($tenantId, $review->id, $entityId, $account, $currentBalance);
                if ($finding) {
                    $findingsCreated++;
                }
            }

            // Rule 4: Inactive account with sudden activity
            if ($hasCurrentActivity && $lastActivityDate && $lastActivityDate->diffInDays($currentMonth) > self::INACTIVE_DAYS) {
                $finding = $this->createInactiveAccountFinding($tenantId, $review->id, $entityId, $account, $lastActivityDate);
                if ($finding) {
                    $findingsCreated++;
                }
            }

            // Rule 5: Missing documentation on large lines
            $missingDocFinding = $this->detectMissingDocumentation($tenantId, $review->id, $entityId, $account, $currentMonth);
            if ($missingDocFinding) {
                $findingsCreated++;
            }

            // Update review risk status based on findings
            $this->updateReviewRisk($review);
        }

        // Rule 6: Unposted journal entries (global, not per-account)
        $unpostedFinding = $this->detectUnpostedJournalEntries($tenantId, $entityId, $currentMonth);
        if ($unpostedFinding) {
            $findingsCreated++;
        }

        // Rule 7: Overdue AR invoices (global)
        $overdueFinding = $this->detectOverdueInvoices($tenantId, $entityId, $currentMonth);
        if ($overdueFinding) {
            $findingsCreated++;
        }

        // Rule 8: Unreconciled bank transactions (global)
        $closeCycle = CloseCycle::where('tenant_id', $tenantId)
            ->where('entity_id', $entityId)
            ->where('period_id', $periodId)
            ->latest()
            ->first();
        $bankCutoff = $this->bankReconciliationService->cutoffForClosePeriod($periodId, $closeCycle?->metadata ?? []);
        $unreconciledFinding = $this->detectUnreconciledBankTransactions($tenantId, $entityId, $bankCutoff);
        if ($unreconciledFinding) {
            $findingsCreated++;
        }

        Log::info('FinancialClose AI: Analysis complete', [
            'findings_created' => $findingsCreated,
            'reviews_created' => $reviewsCreated,
        ]);

        return [
            'findings_created' => $findingsCreated,
            'accounts_analyzed' => $accounts->count(),
        ];
    }

    /**
     * Resolve a period id (which may be a free-form string) to a Carbon month.
     * Falls back to the current month if no YYYY-MM pattern is found.
     */
    private function resolvePeriodMonth(string $periodId): Carbon
    {
        if (preg_match('/(\d{4})-(\d{2})/', $periodId, $matches)) {
            return Carbon::createFromDate((int) $matches[1], (int) $matches[2], 1)->startOfMonth();
        }

        Log::warning('FinancialClose AI: period_id does not contain YYYY-MM, falling back to current month', [
            'period_id' => $periodId,
        ]);

        return now()->startOfMonth();
    }

    /**
     * Ensure an AccountReview exists for this account/entity.
     */
    private function ensureReview(string $tenantId, string $entityId, ChartOfAccount $account): AccountReview
    {
        $review = AccountReview::where('tenant_id', $tenantId)
            ->where('entity_id', $entityId)
            ->where('account_code', $account->code)
            ->first();

        if (! $review) {
            $review = AccountReview::create([
                'tenant_id' => $tenantId,
                'entity_id' => $entityId,
                'book_id' => 'book-001',
                'account_code' => $account->code,
                'account_name' => $account->name,
                'status' => 'not_started',
                'risk_status' => 'normal',
                'assigned_to' => null,
                'due_date' => now()->addDays(5),
                'metadata' => null,
            ]);
        }

        return $review;
    }

    /**
     * Calculate net balance for an account in a given month.
     */
    private function periodBalance(int $accountId, Carbon $month): float
    {
        $result = JournalEntryLine::where('account_id', $accountId)
            ->whereHas('journalEntry', function ($q) use ($month) {
                $q->where('status', 'posted')
                    ->whereBetween('entry_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()]);
            })
            ->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->first();

        $debit = (float) ($result->total_debit ?? 0);
        $credit = (float) ($result->total_credit ?? 0);

        $account = ChartOfAccount::find($accountId);
        if (! $account) {
            return 0;
        }

        if (in_array($account->type, ['asset', 'expense'])) {
            return $debit - $credit;
        }

        return $credit - $debit;
    }

    private function hasActivityInPeriod(int $accountId, Carbon $month): bool
    {
        return JournalEntryLine::where('account_id', $accountId)
            ->whereHas('journalEntry', function ($q) use ($month) {
                $q->where('status', 'posted')
                    ->whereBetween('entry_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()]);
            })
            ->exists();
    }

    private function lastActivityDate(int $accountId): ?Carbon
    {
        $line = JournalEntryLine::where('account_id', $accountId)
            ->whereHas('journalEntry', function ($q) {
                $q->where('status', 'posted');
            })
            ->join('journal_entries as je', 'je.id', '=', 'journal_entry_lines.journal_entry_id')
            ->orderBy('je.entry_date', 'desc')
            ->select('je.entry_date')
            ->first();

        return $line ? Carbon::parse($line->entry_date) : null;
    }

    private function createVarianceFinding(
        string $tenantId,
        string $reviewId,
        string $entityId,
        ChartOfAccount $account,
        float $currentBalance,
        float $priorBalance,
        float $variance
    ): ?AiFinding {
        $direction = $variance > 0 ? 'increased' : 'decreased';
        $variancePct = round(abs($variance) * 100, 1);

        $sourceRefs = $this->accountJournalEntryRefs($account->id, $this->currentMonth);

        return $this->findingService->createFinding(
            $tenantId,
            $reviewId,
            $entityId,
            $account->code,
            [
                'finding_type' => 'anomaly',
                'description' => "Account {$account->code} ({$account->name}) {$direction} {$variancePct}% vs prior month. Current: ".number_format($currentBalance, 2).', Prior: '.number_format($priorBalance, 2).'.',
                'confidence' => min(0.95, 0.70 + ($variancePct / 100)),
                'risk_status' => $variancePct > 50 ? 'high' : 'medium',
                'likely_cause' => 'Period-over-period variance exceeds normal threshold. Review underlying journal entries for one-time items or misclassifications.',
                'recommendation' => 'Verify supporting documentation for large entries and confirm classification accuracy.',
                'detail_items' => [
                    ['label' => 'Current Balance', 'value' => number_format($currentBalance, 2)],
                    ['label' => 'Prior Balance',   'value' => number_format($priorBalance, 2)],
                    ['label' => 'Variance %',      'value' => $variancePct.'%'],
                    ['label' => 'Account Type',    'value' => $account->type],
                ],
                'source_refs' => $sourceRefs,
            ]
        );
    }

    private function createNegativeBalanceFinding(
        string $tenantId,
        string $reviewId,
        string $entityId,
        ChartOfAccount $account,
        float $balance
    ): ?AiFinding {
        $risk = 'high';
        $confidence = 0.92;

        if ($account->type === 'asset') {
            $description = "Asset account {$account->code} ({$account->name}) shows a negative balance of ".number_format(abs($balance), 2).'. Asset accounts should normally carry a debit balance.';
            $cause = 'Possible over-credit, misclassification, or unrecorded reversal.';
            $recommendation = 'Investigate recent credits and confirm no journal entry was posted in reverse.';
        } else {
            $description = "Account {$account->code} ({$account->name}) shows an unexpected negative balance of ".number_format(abs($balance), 2).'. This account type normally carries a credit balance.';
            $cause = 'Possible over-debit, misclassification, or unrecorded accrual reversal.';
            $recommendation = 'Review recent debits and verify no entries were posted to the wrong side.';
        }

        $sourceRefs = $this->accountJournalEntryRefs($account->id, $this->currentMonth);

        return $this->findingService->createFinding(
            $tenantId,
            $reviewId,
            $entityId,
            $account->code,
            [
                'finding_type' => 'anomaly',
                'description' => $description,
                'confidence' => $confidence,
                'risk_status' => $risk,
                'likely_cause' => $cause,
                'recommendation' => $recommendation,
                'detail_items' => [
                    ['label' => 'Current Balance', 'value' => number_format($balance, 2)],
                    ['label' => 'Account Type',    'value' => $account->type],
                ],
                'source_refs' => $sourceRefs,
            ]
        );
    }

    private function createInactiveAccountFinding(
        string $tenantId,
        string $reviewId,
        string $entityId,
        ChartOfAccount $account,
        Carbon $lastActivityDate
    ): ?AiFinding {
        $sourceRefs = $this->accountJournalEntryRefs($account->id, $this->currentMonth);

        return $this->findingService->createFinding(
            $tenantId,
            $reviewId,
            $entityId,
            $account->code,
            [
                'finding_type' => 'anomaly',
                'description' => "Account {$account->code} ({$account->name}) had no activity since {$lastActivityDate->format('M d, Y')} and now shows current-period activity.",
                'confidence' => 0.85,
                'risk_status' => 'medium',
                'likely_cause' => 'Dormant account reactivated. Could be a one-time correction, reclassification, or fraud indicator.',
                'recommendation' => 'Review the current-period entries and confirm business justification.',
                'detail_items' => [
                    ['label' => 'Last Activity',   'value' => $lastActivityDate->format('Y-m-d')],
                    ['label' => 'Account Type',    'value' => $account->type],
                ],
                'source_refs' => $sourceRefs,
            ]
        );
    }

    private function detectMissingDocumentation(
        string $tenantId,
        string $reviewId,
        string $entityId,
        ChartOfAccount $account,
        Carbon $month
    ): ?AiFinding {
        $threshold = 5000;

        $lines = JournalEntryLine::where('account_id', $account->id)
            ->whereHas('journalEntry', function ($q) use ($month) {
                $q->where('status', 'posted')
                    ->whereBetween('entry_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()]);
            })
            ->where(function ($q) {
                $q->whereNull('description')
                    ->orWhere('description', '')
                    ->orWhereNull('reference')
                    ->orWhere('reference', '');
            })
            ->whereRaw('(debit + credit) > ?', [$threshold])
            ->get();

        if ($lines->isEmpty()) {
            return null;
        }

        $totalAmount = $lines->sum(fn ($l) => (float) $l->debit + (float) $l->credit);
        $count = $lines->count();

        $sourceRefs = $lines->take(10)->map(fn ($l) => [
            'type' => 'journal_entry',
            'id' => (string) $l->journal_entry_id,
            'label' => $this->journalLabel($l->journal_entry_id, $l->journalEntry?->entry_number),
            'route' => '/accounting/core/journal-entries?entry_id='.$l->journal_entry_id,
            'meta' => [
                'line_id' => (string) $l->id,
                'amount' => number_format((float) $l->debit + (float) $l->credit, 2),
                'reference' => $l->reference,
            ],
        ])->values()->all();

        return $this->findingService->createFinding(
            $tenantId,
            $reviewId,
            $entityId,
            $account->code,
            [
                'finding_type' => 'missing_support',
                'description' => "{$count} large journal line(s) in account {$account->code} ({$account->name}) totaling ".number_format($totalAmount, 2).' lack description or reference documentation.',
                'confidence' => 0.88,
                'risk_status' => 'medium',
                'likely_cause' => 'Data entry oversight or batch import without documentation.',
                'recommendation' => 'Add descriptions and references to these journal lines before sign-off.',
                'detail_items' => [
                    ['label' => 'Lines Affected', 'value' => (string) $count],
                    ['label' => 'Total Amount',   'value' => number_format($totalAmount, 2)],
                ],
                'source_refs' => $sourceRefs,
            ]
        );
    }

    /**
     * Build source references for journal entry lines on an account in a period.
     * Returns up to 15 refs so users can jump straight to the transactions
     * that triggered a variance / negative-balance / inactive finding.
     */
    private function accountJournalEntryRefs(int $accountId, Carbon $month): array
    {
        $lines = JournalEntryLine::where('account_id', $accountId)
            ->whereHas('journalEntry', function ($q) use ($month) {
                $q->where('status', 'posted')
                    ->whereBetween('entry_date', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()]);
            })
            ->orderBy('id', 'desc')
            ->limit(15)
            ->get(['id', 'journal_entry_id', 'debit', 'credit', 'description', 'reference']);

        return $lines->map(fn ($l) => [
            'type' => 'journal_entry',
            'id' => (string) $l->journal_entry_id,
            'label' => $this->journalLabel($l->journal_entry_id, $l->journalEntry?->entry_number),
            'route' => '/accounting/core/journal-entries?entry_id='.$l->journal_entry_id,
            'meta' => [
                'line_id' => (string) $l->id,
                'amount' => number_format((float) $l->debit + (float) $l->credit, 2),
                'reference' => $l->reference,
            ],
        ])->values()->all();
    }

    /**
     * Human-readable journal reference for AI-finding source chips.
     *
     * Uses the real entry number (e.g. JE-2026-0238) so reviewers can track
     * the transaction, instead of an opaque internal id. Falls back to the id
     * only if the number is somehow unavailable.
     */
    private function journalLabel($journalEntryId, ?string $entryNumber): string
    {
        $number = trim((string) ($entryNumber ?? ''));

        return $number !== '' ? $number : ('Journal Entry #'.$journalEntryId);
    }

    private function updateReviewRisk(AccountReview $review): void
    {
        $openFindingsCount = AiFinding::where('review_id', $review->id)
            ->where('status', 'open')
            ->count();

        $hasCritical = AiFinding::where('review_id', $review->id)
            ->where('status', 'open')
            ->where('risk_status', 'critical')
            ->exists();

        $hasHigh = AiFinding::where('review_id', $review->id)
            ->where('status', 'open')
            ->where('risk_status', 'high')
            ->exists();

        $newRisk = 'normal';
        if ($hasCritical) {
            $newRisk = 'critical';
        } elseif ($hasHigh) {
            $newRisk = 'high';
        } elseif ($openFindingsCount > 0) {
            $newRisk = 'watch';
        }

        if ($review->risk_status !== $newRisk) {
            $review->update(['risk_status' => $newRisk]);
        }
    }

    /**
     * Rule 6: Detect unposted (draft) journal entries.
     */
    private function detectUnpostedJournalEntries(string $tenantId, string $entityId, Carbon $currentMonth): ?AiFinding
    {
        $count = (int) DB::table('journal_entries')->where('status', 'draft')->count();

        if ($count === 0) {
            return null;
        }

        $totalAmount = DB::table('journal_entries')
            ->where('status', 'draft')
            ->join('journal_entry_lines', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->sum('debit');

        $draftEntries = DB::table('journal_entries')
            ->where('status', 'draft')
            ->orderBy('entry_date', 'desc')
            ->limit(15)
            ->get(['id', 'entry_number', 'entry_date', 'reference', 'description']);

        $sourceRefs = $draftEntries->map(fn ($je) => [
            'type' => 'journal_entry',
            'id' => (string) $je->id,
            'label' => $this->journalLabel($je->id, $je->entry_number ?? null),
            'route' => '/accounting/core/journal-entries?entry_id='.$je->id,
            'meta' => [
                'date' => $je->entry_date,
                'reference' => $je->reference,
            ],
        ])->values()->all();

        return $this->findingService->createFinding(
            $tenantId,
            null,
            $entityId,
            null,
            [
                'finding_type' => 'unposted_entries',
                'description' => "{$count} draft journal entries found totaling ".number_format((float) $totalAmount, 2).'. These entries have not been posted to the GL.',
                'confidence' => 0.95,
                'risk_status' => 'high',
                'likely_cause' => 'Journal entries created but not reviewed/approved for posting.',
                'recommendation' => 'Review and post draft journal entries before closing the period.',
                'detail_items' => [
                    ['label' => 'Draft Entries', 'value' => (string) $count],
                    ['label' => 'Total Amount',  'value' => number_format((float) $totalAmount, 2)],
                ],
                'source_refs' => $sourceRefs,
            ]
        );
    }

    /**
     * Rule 7: Detect overdue customer invoices.
     */
    private function detectOverdueInvoices(string $tenantId, string $entityId, Carbon $currentMonth): ?AiFinding
    {
        $overdueQuery = DB::table('invoices')
            ->whereIn('status', ['sent', 'partial', 'posted'])
            ->where('due_date', '<', now()->toDateString());

        $count = $overdueQuery->count();

        if ($count === 0) {
            return null;
        }

        $totalAmount = $overdueQuery->sum('balance');

        $overdueInvoices = $overdueQuery
            ->orderBy('due_date', 'asc')
            ->limit(15)
            ->get(['id', 'invoice_number', 'due_date', 'balance', 'status']);

        $sourceRefs = $overdueInvoices->map(fn ($inv) => [
            'type' => 'invoice',
            'id' => (string) $inv->id,
            'label' => ($inv->invoice_number ?? 'Invoice #'.$inv->id),
            'route' => '/invoices/'.$inv->id,
            'meta' => [
                'due_date' => $inv->due_date,
                'balance' => number_format((float) $inv->balance, 2),
                'status' => $inv->status,
            ],
        ])->values()->all();

        return $this->findingService->createFinding(
            $tenantId,
            null,
            $entityId,
            null,
            [
                'finding_type' => 'overdue_invoices',
                'description' => "{$count} customer invoices are overdue totaling ".number_format((float) $totalAmount, 2).'. These invoices are past their due date.',
                'confidence' => 0.98,
                'risk_status' => 'high',
                'likely_cause' => 'Customers have not paid invoices by the due date.',
                'recommendation' => 'Follow up with customers on overdue invoices and consider bad debt provisions.',
                'detail_items' => [
                    ['label' => 'Overdue Invoices', 'value' => (string) $count],
                    ['label' => 'Total Outstanding', 'value' => number_format((float) $totalAmount, 2)],
                ],
                'source_refs' => $sourceRefs,
            ]
        );
    }

    /**
     * Rule 8: Detect unreconciled bank transactions.
     */
    private function detectUnreconciledBankTransactions(string $tenantId, string $entityId, Carbon $cutoff): ?AiFinding
    {
        $result = $this->bankReconciliationService->unresolvedThrough($cutoff);
        $count = $result['count'];
        if ($count === 0) {
            return null;
        }

        $usesPersistedStatements = $result['mode'] === 'persisted_statement';
        $totalResidual = $result['total_residual_amount'];

        return $this->findingService->createFinding(
            $tenantId,
            null,
            $entityId,
            null,
            [
                'finding_type' => 'unreconciled_transactions',
                'description' => "{$count} unreconciled ".($usesPersistedStatements ? 'statement transactions' : 'bank GL lines')." found through {$result['cutoff_date']}. Total residual exposure: ".number_format($totalResidual, 2).'.',
                'confidence' => 0.95,
                'risk_status' => 'medium',
                'likely_cause' => $usesPersistedStatements ? 'Imported statement transactions remain unmatched, suggested, or partially matched.' : 'This tenant has not adopted persisted statement imports, so legacy unreconciled bank GL lines were used.',
                'recommendation' => 'Complete bank reconciliation for all active bank accounts before closing the period.',
                'detail_items' => [
                    ['label' => $usesPersistedStatements ? 'Unreconciled Statement Transactions' : 'Unreconciled GL Lines', 'value' => (string) $count],
                    ['label' => 'Total Residual Exposure', 'value' => number_format($totalResidual, 2)],
                    ['label' => 'Cutoff Date', 'value' => $result['cutoff_date']],
                    ['label' => 'Source Mode', 'value' => $result['mode']],
                ],
                'source_refs' => $result['source_refs'],
            ]
        );
    }
}
