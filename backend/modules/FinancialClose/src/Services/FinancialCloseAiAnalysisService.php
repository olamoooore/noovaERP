<?php

namespace Modules\FinancialClose\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\AccountReview;
use Modules\FinancialClose\Models\AiFinding;

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
 */
class FinancialCloseAiAnalysisService
{
    private const VARIANCE_THRESHOLD = 0.30;   // 30%
    private const LARGE_BALANCE_THRESHOLD = 1000;
    private const INACTIVE_DAYS = 90;

    public function __construct(
        private readonly FinancialCloseAiFindingService $findingService,
    ) {}

    /**
     * Run AI analysis for a tenant/entity/period and generate findings.
     *
     * @param string $tenantId
     * @param string $entityId
     * @param string $periodId  Format YYYY-MM
     * @return array Summary of findings created
     */
    public function analyze(string $tenantId, string $entityId, string $periodId): array
    {
        Log::info('FinancialClose AI: Starting analysis', [
            'tenant_id' => $tenantId,
            'entity_id' => $entityId,
            'period_id' => $periodId,
        ]);

        $currentMonth = Carbon::createFromFormat('Y-m', $periodId)->startOfMonth();
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

        Log::info('FinancialClose AI: Analysis complete', [
            'findings_created' => $findingsCreated,
            'reviews_created'  => $reviewsCreated,
        ]);

        return [
            'findings_created' => $findingsCreated,
            'accounts_analyzed' => $accounts->count(),
        ];
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

        if (!$review) {
            $review = AccountReview::create([
                'tenant_id'   => $tenantId,
                'entity_id'   => $entityId,
                'book_id'     => 'book-001',
                'account_code'=> $account->code,
                'account_name'=> $account->name,
                'status'      => 'not_started',
                'risk_status' => 'normal',
                'assigned_to' => null,
                'due_date'    => now()->addDays(5),
                'metadata'    => null,
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
        if (!$account) {
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

        return $this->findingService->createFinding(
            $tenantId,
            $reviewId,
            $entityId,
            $account->code,
            [
                'finding_type'    => 'anomaly',
                'description'     => "Account {$account->code} ({$account->name}) {$direction} {$variancePct}% vs prior month. Current: " . number_format($currentBalance, 2) . ', Prior: ' . number_format($priorBalance, 2) . '.',
                'confidence'      => min(0.95, 0.70 + ($variancePct / 100)),
                'risk_status'     => $variancePct > 50 ? 'high' : 'medium',
                'likely_cause'    => 'Period-over-period variance exceeds normal threshold. Review underlying journal entries for one-time items or misclassifications.',
                'recommendation'  => 'Verify supporting documentation for large entries and confirm classification accuracy.',
                'detail_items'    => [
                    ['label' => 'Current Balance', 'value' => number_format($currentBalance, 2)],
                    ['label' => 'Prior Balance',   'value' => number_format($priorBalance, 2)],
                    ['label' => 'Variance %',      'value' => $variancePct . '%'],
                    ['label' => 'Account Type',    'value' => $account->type],
                ],
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
            $description = "Asset account {$account->code} ({$account->name}) shows a negative balance of " . number_format(abs($balance), 2) . '. Asset accounts should normally carry a debit balance.';
            $cause = 'Possible over-credit, misclassification, or unrecorded reversal.';
            $recommendation = 'Investigate recent credits and confirm no journal entry was posted in reverse.';
        } else {
            $description = "Account {$account->code} ({$account->name}) shows an unexpected negative balance of " . number_format(abs($balance), 2) . '. This account type normally carries a credit balance.';
            $cause = 'Possible over-debit, misclassification, or unrecorded accrual reversal.';
            $recommendation = 'Review recent debits and verify no entries were posted to the wrong side.';
        }

        return $this->findingService->createFinding(
            $tenantId,
            $reviewId,
            $entityId,
            $account->code,
            [
                'finding_type'    => 'anomaly',
                'description'     => $description,
                'confidence'      => $confidence,
                'risk_status'     => $risk,
                'likely_cause'    => $cause,
                'recommendation'  => $recommendation,
                'detail_items'    => [
                    ['label' => 'Current Balance', 'value' => number_format($balance, 2)],
                    ['label' => 'Account Type',    'value' => $account->type],
                ],
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
        return $this->findingService->createFinding(
            $tenantId,
            $reviewId,
            $entityId,
            $account->code,
            [
                'finding_type'    => 'anomaly',
                'description'     => "Account {$account->code} ({$account->name}) had no activity since {$lastActivityDate->format('M d, Y')} and now shows current-period activity.",
                'confidence'      => 0.85,
                'risk_status'     => 'medium',
                'likely_cause'    => 'Dormant account reactivated. Could be a one-time correction, reclassification, or fraud indicator.',
                'recommendation'  => 'Review the current-period entries and confirm business justification.',
                'detail_items'    => [
                    ['label' => 'Last Activity',   'value' => $lastActivityDate->format('Y-m-d')],
                    ['label' => 'Account Type',    'value' => $account->type],
                ],
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

        return $this->findingService->createFinding(
            $tenantId,
            $reviewId,
            $entityId,
            $account->code,
            [
                'finding_type'    => 'missing_support',
                'description'     => "{$count} large journal line(s) in account {$account->code} ({$account->name}) totaling " . number_format($totalAmount, 2) . ' lack description or reference documentation.',
                'confidence'      => 0.88,
                'risk_status'     => 'medium',
                'likely_cause'    => 'Data entry oversight or batch import without documentation.',
                'recommendation'  => 'Add descriptions and references to these journal lines before sign-off.',
                'detail_items'    => [
                    ['label' => 'Lines Affected', 'value' => (string) $count],
                    ['label' => 'Total Amount',   'value' => number_format($totalAmount, 2)],
                ],
            ]
        );
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
}
