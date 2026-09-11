<?php

namespace App\Services\BankStatements;

use App\Models\BankStatementImport;
use App\Models\BankStatementMatch;
use App\Models\BankStatementTransaction;
use App\Models\JournalEntryLine;
use Illuminate\Support\Facades\DB;

class BankStatementMatchingService
{
    private const TOLERANCE = 0.01;

    public function suggest(string $importId): array
    {
        $import = BankStatementImport::with('bankAccount')->findOrFail($importId);
        if ($import->status !== 'imported') {
            throw new \DomainException('Only successfully imported statements can be matched.');
        }
        $lines = BankStatementTransaction::with(['allocations', 'import'])->where('import_id', $importId)->whereIn('status', ['unmatched', 'suggested', 'partially_matched'])->get();
        $glLines = $this->eligibleGlLines((int) $import->bank_account_id)->get();
        $created = 0;
        foreach ($lines as $statement) {
            $invoiceReferences = $statement->allocations->where('allocation_type', 'invoice_reference')
                ->pluck('external_reference')->map(fn ($value) => $this->normalize($value))->filter()->values();
            $ranked = $glLines->map(function ($gl) use ($statement, $invoiceReferences) {
                $movement = (float) $gl->debit - (float) $gl->credit;
                if ($movement * (float) $statement->amount <= 0) {
                    return null;
                }
                $dateGap = abs($statement->transaction_date->diffInDays($gl->journalEntry->entry_date));
                if ($dateGap > 10) {
                    return null;
                }
                $amountScore = abs(abs($movement) - abs((float) $statement->residual_amount)) <= self::TOLERANCE ? 0.45 : 0;
                $dateScore = 0.05 * max(0, 1 - ($dateGap / 10));
                $statementRef = $this->normalize($statement->bank_reference);
                $glRef = $this->normalize($gl->reference ?: $gl->journalEntry->reference);
                $payment = DB::table('payments as p')->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
                    ->leftJoin('invoices as i', 'i.id', '=', 'p.invoice_id')->where('p.journal_entry_id', $gl->journal_entry_id)
                    ->whereNull('p.deleted_at')->select('p.reference', 'p.payment_number', 'c.code as customer_code', 'c.name as customer_name', 'i.invoice_number')->first();
                $paymentRefs = collect([$glRef, $this->normalize($payment?->reference), $this->normalize($payment?->payment_number)])->filter();
                $referenceMatch = $statementRef !== '' && $paymentRefs->contains($statementRef);
                $referenceConflict = $statementRef !== '' && $paymentRefs->isNotEmpty() && ! $referenceMatch;
                $invoiceMatch = $invoiceReferences->isNotEmpty() && $invoiceReferences->contains($this->normalize($payment?->invoice_number));
                $importMapping = $statement->import->mapping ?? [];
                $mappedCustomerHeader = $importMapping['customer_code'] ?? null;
                $statementCustomerCode = $this->normalize($statement->customer_code
                    ?: ($mappedCustomerHeader ? ($statement->raw_data[$mappedCustomerHeader] ?? null) : null));
                $customerCodeMatch = $statementCustomerCode !== '' && $statementCustomerCode === $this->normalize($payment?->customer_code);
                $customerName = $this->normalize($payment?->customer_name);
                $payerText = $this->normalize(($statement->customer_reference ?? '').' '.$statement->description);
                $customerNameMatch = $customerName !== '' && str_contains($payerText, $customerName);
                $customerConflict = $statementCustomerCode !== '' && $payment?->customer_code && ! $customerCodeMatch;
                $score = $amountScore + ($referenceMatch ? 0.25 : 0) + ($invoiceMatch ? 0.15 : 0)
                    + (($customerCodeMatch || $customerNameMatch) ? 0.10 : 0) + $dateScore
                    - ($referenceConflict ? 0.15 : 0) - ($customerConflict ? 0.20 : 0);

                return ['line' => $gl, 'score' => max(0, $score),
                    'allocation' => abs($movement) < abs((float) $statement->residual_amount) ? $movement : (float) $statement->residual_amount,
                    'reasons' => ['exact_amount' => $amountScore > 0, 'reference_match' => $referenceMatch, 'reference_conflict' => $referenceConflict,
                        'invoice_match' => $invoiceMatch, 'customer_code_match' => $customerCodeMatch, 'customer_name_match' => $customerNameMatch,
                        'customer_conflict' => $customerConflict, 'date_gap_days' => $dateGap]];
            })->filter()->sortByDesc('score')->values();
            $topScore = $ranked->first()['score'] ?? null;
            $ambiguous = $topScore !== null && $ranked->filter(fn ($candidate) => abs($candidate['score'] - $topScore) < 0.0001)->count() > 1;
            foreach ($ranked->take(5) as $candidate) {
                $reasons = [...$candidate['reasons'],
                    'ambiguous' => $ambiguous && abs($candidate['score'] - $topScore) < 0.0001,
                    'confidence_band' => $candidate['score'] >= 0.95 && ! $ambiguous ? 'bulk_eligible' : ($candidate['score'] >= 0.75 ? 'review_required' : 'possible_candidate')];
                BankStatementMatch::updateOrCreate(
                    ['bank_statement_transaction_id' => $statement->id, 'journal_entry_line_id' => $candidate['line']->id, 'status' => 'proposed'],
                    ['matched_amount' => $candidate['allocation'], 'match_type' => 'suggested', 'confidence' => $candidate['score'], 'match_reasons' => $reasons]
                );
                $created++;
            }
            if ($ranked->isNotEmpty() && $statement->status === 'unmatched') {
                $statement->update(['status' => 'suggested']);
            }
        }

        return ['suggestions_created' => $created];
    }

    public function accept(array $data, int $userId): BankStatementMatch
    {
        return DB::transaction(function () use ($data, $userId) {
            $replay = BankStatementMatch::where('idempotency_key', $data['idempotency_key'])->first();
            if ($replay) {
                return $replay;
            }
            $statement = BankStatementTransaction::lockForUpdate()->findOrFail($data['bank_statement_transaction_id']);
            $gl = JournalEntryLine::with('journalEntry')->lockForUpdate()->findOrFail($data['journal_entry_line_id']);
            $account = $statement->bankAccount()->firstOrFail();
            if ($statement->import()->value('status') !== 'imported') {
                throw new \DomainException('Statement import is not ready for matching.');
            }
            if (in_array($statement->status, ['matched', 'excluded', 'reversed'], true)) {
                throw new \DomainException('Statement transaction is not available for matching.');
            }
            if ((int) $gl->account_id !== (int) $account->gl_account_id || $gl->journalEntry?->status !== 'posted') {
                throw new \DomainException('GL line is not a posted line for the selected bank account.');
            }
            if ($gl->reconciliation_id !== null) {
                throw new MatchConflict('GL line is already reconciled.');
            }
            $amount = (float) $data['matched_amount'];
            $movement = (float) $gl->debit - (float) $gl->credit;
            if ($amount * (float) $statement->amount <= 0 || $amount * $movement <= 0) {
                throw new \DomainException('Match amount direction must agree with the statement and GL movements.');
            }
            $statementRemaining = (float) $statement->residual_amount;
            $glAllocated = (float) BankStatementMatch::where('journal_entry_line_id', $gl->id)->where('status', 'accepted')->sum('matched_amount');
            $glRemaining = $movement - $glAllocated;
            if (abs($amount) - abs($statementRemaining) > self::TOLERANCE || abs($amount) - abs($glRemaining) > self::TOLERANCE) {
                throw new MatchConflict('Match amount exceeds the available statement or GL amount.');
            }
            $match = BankStatementMatch::create(['bank_statement_transaction_id' => $statement->id, 'journal_entry_line_id' => $gl->id, 'matched_amount' => $amount, 'match_type' => 'manual', 'confidence' => null, 'match_reasons' => ['manual' => true], 'review_reason' => $data['review_reason'], 'status' => 'accepted', 'accepted_by' => $userId, 'accepted_at' => now(), 'idempotency_key' => $data['idempotency_key']]);
            $this->refreshStatement($statement);

            return $match;
        });
    }

    public function remove(string $matchId): void
    {
        DB::transaction(function () use ($matchId) {
            $match = BankStatementMatch::lockForUpdate()->findOrFail($matchId);
            if ($match->reconciliation_id !== null) {
                throw new MatchConflict('A completed reconciliation match cannot be removed.');
            }
            $statement = BankStatementTransaction::lockForUpdate()->findOrFail($match->bank_statement_transaction_id);
            $match->update(['status' => 'reversed', 'version' => $match->version + 1]);
            $this->refreshStatement($statement);
        });
    }

    private function refreshStatement(BankStatementTransaction $statement): void
    {
        $matched = (float) BankStatementMatch::where('bank_statement_transaction_id', $statement->id)->where('status', 'accepted')->sum('matched_amount');
        $residual = (float) $statement->amount - $matched;
        $status = abs($residual) <= self::TOLERANCE ? 'matched' : (abs($matched) > self::TOLERANCE ? 'partially_matched' : 'unmatched');
        $statement->update(['matched_amount' => $matched, 'residual_amount' => $residual, 'status' => $status]);
    }

    private function eligibleGlLines(int $bankAccountId)
    {
        $glAccountId = DB::table('bank_accounts')->where('id', $bankAccountId)->value('gl_account_id');

        return JournalEntryLine::with('journalEntry')->where('account_id', $glAccountId)->whereNull('reconciliation_id')->whereHas('journalEntry', fn ($q) => $q->where('status', 'posted'));
    }

    private function normalize(?string $value): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $value ?? ''));
    }
}
