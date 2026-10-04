<?php

namespace App\Services\Reconciliation;

use App\Models\BankReconciliation;
use App\Models\BankStatementMatch;
use App\Models\BankStatementTransaction;
use App\Models\JournalEntryLine;
use App\Models\ReconciliationItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AutoMatchingService
 *
 * Provides intelligent auto-matching algorithms for bank reconciliation.
 * Supports exact matching, fuzzy matching on references, and amount-only matching.
 */
class AutoMatchingService
{
    /**
     * Issue #840 (BK8): only these statement rows may participate in matching.
     * Mirrors BankStatementMatchingService's availability gate so a row
     * compensated by a failed import (reversed) can never be matched here.
     */
    private const MATCHABLE_STATUSES = ['unmatched', 'suggested', 'partially_matched'];

    /**
     * Find matching suggestions for bank reconciliation.
     *
     * @param  array  $bankTransactions  Array of bank statement transactions. Each item should
     *                                   contain id, date, amount, reference, description.
     * @param  string|null  $asOfDate  Optional date cutoff
     * @return array Matching suggestions with confidence scores
     */
    public function findMatches(int $bankAccountId, array $bankTransactions, ?string $asOfDate = null): array
    {
        $suggestions = [];

        // Get unreconciled GL transactions for this bank account's linked GL account
        $glTransactions = $this->getUnreconciledGLTransactions($bankAccountId, $asOfDate);

        foreach ($bankTransactions as $bankTxn) {
            $matches = [];
            $statementLineId = $bankTxn['id'] ?? null;

            // Try exact match first
            $exactMatches = $this->findExactMatches($bankTxn, $glTransactions);
            foreach ($exactMatches as $match) {
                $matches[] = [
                    'gl_transaction_id' => $match['id'],
                    'match_type' => 'exact',
                    'confidence' => 100.00,
                    'reason' => 'Exact match on amount, date, and reference',
                    'gl_data' => $match,
                ];
            }

            // If no exact match, try fuzzy matching
            if (empty($matches)) {
                $fuzzyMatches = $this->findFuzzyMatches($bankTxn, $glTransactions);
                foreach ($fuzzyMatches as $match) {
                    $matches[] = [
                        'gl_transaction_id' => $match['id'],
                        'match_type' => 'fuzzy',
                        'confidence' => $match['confidence'],
                        'reason' => $match['reason'],
                        'gl_data' => $match,
                    ];
                }
            }

            // If still no match, try amount-only with date range
            if (empty($matches)) {
                $amountMatches = $this->findAmountOnlyMatches($bankTxn, $glTransactions);
                foreach ($amountMatches as $match) {
                    $matches[] = [
                        'gl_transaction_id' => $match['id'],
                        'match_type' => 'amount_only',
                        'confidence' => $match['confidence'],
                        'reason' => $match['reason'],
                        'gl_data' => $match,
                    ];
                }
            }

            // Persist match candidates against the durable statement line when possible
            $this->persistMatchCandidates($statementLineId, $matches);

            $suggestions[] = [
                'bank_transaction' => $bankTxn,
                'matches' => $matches,
                'match_count' => count($matches),
            ];
        }

        return $suggestions;
    }

    /**
     * Get unreconciled GL transactions for a bank account using its linked GL account.
     */
    private function getUnreconciledGLTransactions(int $bankAccountId, ?string $asOfDate = null): Collection
    {
        $bankAccount = \App\Models\BankAccount::with('glAccount')->find($bankAccountId);
        $glAccountId = $bankAccount?->gl_account_id;

        $query = JournalEntryLine::query()
            ->when($glAccountId, fn ($q) => $q->where('account_id', $glAccountId))
            ->where(function ($q) {
                $q->where('reconciled', false)
                    ->orWhereNull('reconciled');
            })
            ->whereNull('reconciliation_id')
            ->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'))
            ->with(['journalEntry', 'account']);

        if ($asOfDate) {
            $query->whereHas('journalEntry', fn ($q) => $q->where('entry_date', '<=', $asOfDate));
        }

        return $query->get();
    }

    /**
     * Persist suggested match candidates against the live statement-import
     * models (BankStatementTransaction/BankStatementMatch).
     *
     * Issue #168: this previously wrote to App\Models\BankStatementLine /
     * MatchCandidate — a disconnected model pair that no import pipeline ever
     * populates — so suggestions were silently discarded for every real caller.
     * The id supplied by the caller is a BankStatementTransaction id.
     */
    private function persistMatchCandidates(?string $statementTransactionId, array $matches): void
    {
        if (! $statementTransactionId || ! \Illuminate\Support\Str::isUuid((string) $statementTransactionId)) {
            return;
        }

        $transaction = BankStatementTransaction::find($statementTransactionId);
        if (! $transaction || ! in_array($transaction->status, self::MATCHABLE_STATUSES, true)) {
            return;
        }

        foreach ($matches as $match) {
            $existing = BankStatementMatch::query()
                ->where('bank_statement_transaction_id', $transaction->id)
                ->where('journal_entry_line_id', $match['gl_transaction_id'])
                ->first();

            // Never downgrade a match a user already accepted.
            if ($existing && $existing->status === 'accepted') {
                continue;
            }

            BankStatementMatch::updateOrCreate(
                [
                    'bank_statement_transaction_id' => $transaction->id,
                    'journal_entry_line_id' => $match['gl_transaction_id'],
                ],
                [
                    'matched_amount' => abs((float) ($match['gl_data']['net_amount'] ?? 0)),
                    // The live statement pipeline stores 'suggested' proposals
                    // with a 0..1 confidence, so auto-match suggestions land in
                    // the same universe (and its bulk-accept rules).
                    'match_type' => 'suggested',
                    'confidence' => round(((float) $match['confidence']) / 100, 4),
                    'match_reasons' => [
                        'auto_match_type' => $match['match_type'],
                        'reason' => $match['reason'] ?? $match['match_type'],
                    ],
                    'status' => 'proposed',
                ]
            );
        }
    }

    /**
     * Find exact matches (amount + date + reference)
     */
    private function findExactMatches(array $bankTxn, Collection $glTransactions): array
    {
        $matches = [];

        foreach ($glTransactions as $glTxn) {
            $amountMatch = $this->amountsMatch($bankTxn['amount'], $glTxn->debit - $glTxn->credit);
            $glDate = $glTxn->transaction_date ?? $glTxn->journalEntry?->entry_date?->format('Y-m-d');
            $dateMatch = $this->datesMatch($bankTxn['date'], $glDate, 0);
            $referenceMatch = $this->referencesMatch(
                $bankTxn['reference'] ?? '',
                $glTxn->reference ?? '',
                100
            );

            if ($amountMatch && $dateMatch && $referenceMatch) {
                $matches[] = $this->formatGLTransaction($glTxn);
            }
        }

        return $matches;
    }

    /**
     * Find fuzzy matches (amount + date ±3 days + fuzzy reference)
     */
    private function findFuzzyMatches(array $bankTxn, Collection $glTransactions): array
    {
        $matches = [];

        foreach ($glTransactions as $glTxn) {
            $amountMatch = $this->amountsMatch($bankTxn['amount'], (float) $glTxn->debit - (float) $glTxn->credit);

            if (! $amountMatch) {
                continue;
            }

            $glDate = $glTxn->transaction_date ?? $glTxn->journalEntry?->entry_date?->format('Y-m-d');
            $dateDiff = $this->dateDifference($bankTxn['date'], $glDate);

            // Only consider if within 3 days
            if ($dateDiff > 3) {
                continue;
            }

            $referenceSimilarity = $this->calculateReferenceSimilarity(
                $bankTxn['reference'] ?? '',
                $glTxn->reference ?? ''
            );

            // Calculate confidence based on date proximity and reference similarity
            $dateConfidence = 100 - ($dateDiff * 5); // -5% per day difference
            $confidence = ($dateConfidence * 0.3) + ($referenceSimilarity * 0.7);

            if ($confidence >= 80) {
                $match = $this->formatGLTransaction($glTxn);
                $match['confidence'] = round($confidence, 2);
                $match['reason'] = sprintf(
                    'Amount matches, date within %d days, reference %.0f%% similar',
                    $dateDiff,
                    $referenceSimilarity
                );
                $matches[] = $match;
            }
        }

        // Sort by confidence descending
        usort($matches, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        return $matches;
    }

    /**
     * Find amount-only matches (amount + date ±7 days)
     */
    private function findAmountOnlyMatches(array $bankTxn, Collection $glTransactions): array
    {
        $matches = [];

        foreach ($glTransactions as $glTxn) {
            $amountMatch = $this->amountsMatch($bankTxn['amount'], (float) $glTxn->debit - (float) $glTxn->credit);

            if (! $amountMatch) {
                continue;
            }

            $glDate = $glTxn->transaction_date ?? $glTxn->journalEntry?->entry_date?->format('Y-m-d');
            $dateDiff = $this->dateDifference($bankTxn['date'], $glDate);

            // Only consider if within 7 days
            if ($dateDiff > 7) {
                continue;
            }

            // Calculate confidence based on date proximity
            $confidence = 100 - ($dateDiff * 5); // -5% per day, min 65%
            $confidence = max(60, $confidence);

            $match = $this->formatGLTransaction($glTxn);
            $match['confidence'] = round($confidence, 2);
            $match['reason'] = sprintf(
                'Amount matches, date within %d days',
                $dateDiff
            );
            $matches[] = $match;
        }

        // Sort by confidence descending
        usort($matches, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        return $matches;
    }

    /**
     * Check if amounts match within tolerance
     */
    private function amountsMatch(float $amount1, float $amount2): bool
    {
        return abs($amount1 - $amount2) < 0.01;
    }

    /**
     * Check if dates match within tolerance days
     */
    private function datesMatch(string $date1, string $date2, int $toleranceDays): bool
    {
        $diff = $this->dateDifference($date1, $date2);

        return $diff <= $toleranceDays;
    }

    /**
     * Calculate days difference between two dates
     */
    private function dateDifference(string $date1, string $date2): int
    {
        $d1 = new \DateTime($date1);
        $d2 = new \DateTime($date2);

        return abs($d1->diff($d2)->days);
    }

    /**
     * Check if references match with minimum similarity
     */
    private function referencesMatch(string $ref1, string $ref2, float $minSimilarity): bool
    {
        $similarity = $this->calculateReferenceSimilarity($ref1, $ref2);

        return $similarity >= $minSimilarity;
    }

    /**
     * Calculate similarity between two reference strings
     * Uses Levenshtein distance for fuzzy matching
     */
    private function calculateReferenceSimilarity(string $ref1, string $ref2): float
    {
        // Normalize references
        $ref1 = strtoupper(trim($ref1));
        $ref2 = strtoupper(trim($ref2));

        if ($ref1 === $ref2) {
            return 100.0;
        }

        if (empty($ref1) || empty($ref2)) {
            return 0.0;
        }

        // Calculate Levenshtein distance
        $levenshtein = levenshtein($ref1, $ref2);
        $maxLen = max(strlen($ref1), strlen($ref2));

        if ($maxLen === 0) {
            return 100.0;
        }

        $similarity = (1 - ($levenshtein / $maxLen)) * 100;

        return max(0, $similarity);
    }

    /**
     * Format GL transaction for response
     */
    private function formatGLTransaction(JournalEntryLine $glTxn): array
    {
        return [
            'id' => $glTxn->id,
            'transaction_date' => $glTxn->transaction_date ?? $glTxn->journalEntry?->entry_date?->format('Y-m-d'),
            'description' => $glTxn->description,
            'reference' => $glTxn->reference,
            'debit' => $glTxn->debit,
            'credit' => $glTxn->credit,
            'net_amount' => (float) $glTxn->debit - (float) $glTxn->credit,
            'journal_entry_id' => $glTxn->journal_entry_id,
            'account_id' => $glTxn->account_id,
        ];
    }

    /**
     * Accept multiple match suggestions and create reconciliation items
     *
     * @param  array  $acceptedMatches  Array of ['bank_transaction_id', 'gl_transaction_id', 'match_type', 'confidence']
     * @return array Results of acceptance
     */
    public function acceptMatches(int $bankAccountId, ?int $reconciliationId, array $acceptedMatches): array
    {
        $results = [
            'accepted' => 0,
            'failed' => 0,
            'errors' => [],
            'reconciliation_id' => null,
        ];

        DB::beginTransaction();

        try {
            // Issue #168: a reconciliation row is only ever created by the
            // completion step of the other flows, so requiring one made these
            // endpoints uncallable. Reuse the bank account's latest draft (or
            // start one) when the caller does not supply one.
            $reconciliation = $this->resolveDraftReconciliation($bankAccountId, $reconciliationId);
            $results['reconciliation_id'] = $reconciliation->id;

            foreach ($acceptedMatches as $match) {
                try {
                    // Validate the GL transaction exists and is unreconciled
                    $glLine = JournalEntryLine::where('id', $match['gl_transaction_id'])
                        ->whereNull('reconciliation_id')
                        ->first();

                    if (! $glLine) {
                        $results['failed']++;
                        $results['errors'][] = "GL transaction {$match['gl_transaction_id']} not found or already reconciled";

                        continue;
                    }

                    $bankTransactionId = $match['bank_transaction_id'] ?? null;

                    // When the caller supplies a real statement transaction id,
                    // accept the underlying statement match too so both sides of
                    // the reconciliation agree. Issue #840 (BK8): only rows
                    // still available for matching may be accepted — a row
                    // compensated by a failed import (reversed) must be rejected.
                    $statementTransaction = null;
                    $statementMatch = null;

                    if ($bankTransactionId
                        && (\Illuminate\Support\Str::isUuid((string) $bankTransactionId) || is_numeric($bankTransactionId))) {
                        $statementTransaction = BankStatementTransaction::find($bankTransactionId);
                        if ($statementTransaction && ! in_array($statementTransaction->status, self::MATCHABLE_STATUSES, true)) {
                            $results['failed']++;
                            $results['errors'][] = "Statement transaction {$bankTransactionId} is not available for matching.";

                            continue;
                        }
                    }

                    if ($statementTransaction) {
                        $statementMatch = BankStatementMatch::updateOrCreate(
                            [
                                'bank_statement_transaction_id' => $bankTransactionId,
                                'journal_entry_line_id' => $glLine->id,
                            ],
                            [
                                'reconciliation_id' => $reconciliation->id,
                                'matched_amount' => abs((float) $glLine->debit - (float) $glLine->credit),
                                'match_type' => 'manual',
                                'confidence' => isset($match['confidence'])
                                    ? round(((float) $match['confidence']) / 100, 4)
                                    : null,
                                'match_reasons' => [
                                    'accepted_via_auto_match' => true,
                                    'auto_match_type' => $match['match_type'] ?? 'manual',
                                ],
                                'status' => 'accepted',
                                'accepted_by' => \Modules\OCRBookkeeping\Helpers\TenantUserHelper::resolveTenantUserId(),
                                'accepted_at' => now(),
                            ]
                        );
                    }

                    // Create reconciliation item
                    ReconciliationItem::create([
                        'reconciliation_id' => $reconciliation->id,
                        'journal_entry_line_id' => $glLine->id,
                        // reconciliation_items.bank_transaction_id is a legacy
                        // bigint column; the live statement FK is a uuid.
                        'bank_transaction_id' => is_numeric($bankTransactionId) ? (int) $bankTransactionId : null,
                        'bank_statement_transaction_id' => $statementMatch?->bank_statement_transaction_id,
                        'bank_statement_match_id' => $statementMatch?->id,
                        'match_type' => $match['match_type'] ?? 'manual',
                        'match_confidence' => $match['confidence'] ?? null,
                    ]);

                    // Issue #168: actually mark the GL line reconciled — this
                    // method previously created bookkeeping rows without ever
                    // changing journal_entry_lines, so accepted matches had no
                    // effect on the reconciliation state.
                    $glLine->update([
                        'reconciliation_id' => $reconciliation->id,
                        'reconciled' => true,
                        'reconciliation_date' => $reconciliation->statement_date,
                    ]);

                    $results['accepted']++;

                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = "Failed to accept match for GL transaction {$match['gl_transaction_id']}: {$e->getMessage()}";
                }
            }

            DB::commit();

            Log::info('Bulk match acceptance completed', [
                'reconciliation_id' => $reconciliation->id,
                'accepted' => $results['accepted'],
                'failed' => $results['failed'],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return $results;
    }

    /**
     * Issue #168: resolve (or lazily start) the draft reconciliation the
     * accepted matches belong to.
     */
    private function resolveDraftReconciliation(int $bankAccountId, ?int $reconciliationId): BankReconciliation
    {
        if ($reconciliationId !== null) {
            $reconciliation = BankReconciliation::query()
                ->where('id', $reconciliationId)
                ->where('bank_account_id', $bankAccountId)
                ->first();

            if (! $reconciliation) {
                throw new \InvalidArgumentException('The reconciliation does not belong to the given bank account.');
            }

            return $reconciliation;
        }

        $draft = BankReconciliation::query()
            ->where('bank_account_id', $bankAccountId)
            ->where('status', 'draft')
            ->latest('id')
            ->first();

        return $draft ?? BankReconciliation::create([
            'bank_account_id' => $bankAccountId,
            'statement_date' => now()->toDateString(),
            'statement_balance' => 0,
            'opening_balance' => 0,
            'closing_balance' => 0,
            'difference' => 0,
            'status' => 'draft',
        ]);
    }

    /**
     * Get match statistics for analysis
     *
     * @param  array  $suggestions  Array from findMatches()
     * @return array Statistics
     */
    public function getMatchStatistics(array $suggestions): array
    {
        $totalBankTransactions = count($suggestions);
        $withMatches = 0;
        $withHighConfidence = 0; // > 90%
        $withMediumConfidence = 0; // 80-90%
        $withLowConfidence = 0; // 60-80%
        $noMatches = 0;

        foreach ($suggestions as $suggestion) {
            if ($suggestion['match_count'] > 0) {
                $withMatches++;

                $highestConfidence = max(array_column($suggestion['matches'], 'confidence'));

                if ($highestConfidence > 90) {
                    $withHighConfidence++;
                } elseif ($highestConfidence >= 80) {
                    $withMediumConfidence++;
                } else {
                    $withLowConfidence++;
                }
            } else {
                $noMatches++;
            }
        }

        return [
            'total_bank_transactions' => $totalBankTransactions,
            'with_matches' => $withMatches,
            'with_high_confidence' => $withHighConfidence,
            'with_medium_confidence' => $withMediumConfidence,
            'with_low_confidence' => $withLowConfidence,
            'no_matches' => $noMatches,
            'match_rate_percent' => $totalBankTransactions > 0
                ? round(($withMatches / $totalBankTransactions) * 100, 2)
                : 0,
            'high_confidence_rate_percent' => $totalBankTransactions > 0
                ? round(($withHighConfidence / $totalBankTransactions) * 100, 2)
                : 0,
        ];
    }
}
