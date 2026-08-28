<?php

namespace App\Services\Reconciliation;

use App\Models\JournalEntryLine;
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
     * Persist suggested match candidates against a durable BankStatementLine.
     */
    private function persistMatchCandidates(?string $statementLineId, array $matches): void
    {
        if (! $statementLineId || ! is_numeric($statementLineId)) {
            return;
        }

        $line = \App\Models\BankStatementLine::find($statementLineId);
        if (! $line) {
            return;
        }

        foreach ($matches as $match) {
            \App\Models\MatchCandidate::updateOrCreate(
                [
                    'bank_statement_line_id' => $line->id,
                    'journal_entry_line_id' => $match['gl_transaction_id'],
                ],
                [
                    'match_type' => $match['match_type'],
                    'confidence' => $match['confidence'],
                    'reason' => $match['reason'] ?? null,
                    'accepted' => false,
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
    public function acceptMatches(int $bankAccountId, int $reconciliationId, array $acceptedMatches): array
    {
        $results = [
            'accepted' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        DB::beginTransaction();

        try {
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

                    // Create reconciliation item
                    \App\Models\ReconciliationItem::create([
                        'reconciliation_id' => $reconciliationId,
                        'journal_entry_line_id' => $match['gl_transaction_id'],
                        'bank_transaction_id' => $match['bank_transaction_id'] ?? null,
                        'match_type' => $match['match_type'] ?? 'manual',
                        'match_confidence' => $match['confidence'] ?? null,
                    ]);

                    $results['accepted']++;

                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = "Failed to accept match for GL transaction {$match['gl_transaction_id']}: {$e->getMessage()}";
                }
            }

            DB::commit();

            Log::info('Bulk match acceptance completed', [
                'reconciliation_id' => $reconciliationId,
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
