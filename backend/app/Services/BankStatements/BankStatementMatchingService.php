<?php

namespace App\Services\BankStatements;

use App\Models\BankStatementImport;
use App\Models\BankStatementMatch;
use App\Models\BankStatementOperation;
use App\Models\BankStatementTransaction;
use App\Models\JournalEntryLine;
use Illuminate\Support\Facades\DB;

class BankStatementMatchingService
{
    public function __construct(
        private CurrencyToleranceService $tolerances,
        private BankStatementAuditService $audit,
    ) {}

    public function suggest(string $importId, int $actorId): array
    {
        $import = BankStatementImport::with('bankAccount')->findOrFail($importId);
        if ($import->status !== 'imported') {
            throw new \DomainException('Only successfully imported statements can be matched.');
        }
        $lines = BankStatementTransaction::with(['allocations', 'import'])->where('import_id', $importId)->whereIn('status', ['unmatched', 'suggested', 'partially_matched'])->get();
        BankStatementMatch::whereIn('bank_statement_transaction_id', $lines->pluck('id'))
            ->where('status', 'proposed')
            ->delete();
        $glLines = $this->eligibleGlLines((int) $import->bank_account_id)->get();
        $acceptedByGl = BankStatementMatch::query()
            ->whereIn('journal_entry_line_id', $glLines->pluck('id'))
            ->where('status', 'accepted')
            ->selectRaw('journal_entry_line_id, SUM(matched_amount) AS allocated_amount')
            ->groupBy('journal_entry_line_id')
            ->pluck('allocated_amount', 'journal_entry_line_id');
        $searchWindowDays = max(10, (int) config('bank_statements.matching_search_window_days', 45));
        $created = 0;
        foreach ($lines as $statement) {
            $tolerance = (float) $this->tolerances->for($statement->currency_code);
            $invoiceReferences = $statement->allocations->where('allocation_type', 'invoice_reference')
                ->pluck('external_reference')->map(fn ($value) => $this->normalize($value))->filter()->values();
            $rawInvoiceReference = $this->rawValue($statement->raw_data ?? [], ['invoicenumbers', 'invoicereference', 'correctedinvoicenumber']);
            if ($rawInvoiceReference !== null) {
                $invoiceReferences->push($this->normalize($rawInvoiceReference));
            }
            $ranked = $glLines->map(function ($gl) use ($statement, $invoiceReferences, $tolerance, $acceptedByGl, $searchWindowDays) {
                $movement = (float) $gl->debit - (float) $gl->credit;
                $glRemaining = $movement - (float) ($acceptedByGl[$gl->id] ?? 0);
                if (abs($glRemaining) <= $tolerance || $glRemaining * (float) $statement->amount <= 0) {
                    return null;
                }
                $dateGap = abs($statement->transaction_date->diffInDays($gl->journalEntry->entry_date));
                if ($dateGap > $searchWindowDays) {
                    return null;
                }
                $amountScore = abs(abs($glRemaining) - abs((float) $statement->residual_amount)) <= $tolerance ? 0.45 : 0;
                $dateScore = $dateGap <= 3 ? 0.05 : ($dateGap <= 10 ? 0.05 * ((10 - $dateGap) / 7) : 0);
                $statementRef = $this->normalize($statement->bank_reference
                    ?: $this->rawValue($statement->raw_data ?? [], ['ourreference', 'bankreference', 'reference']));
                $glRef = $this->normalize($gl->reference ?: $gl->journalEntry->reference);
                $payment = DB::table('payments as p')->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
                    ->leftJoin('invoices as i', 'i.id', '=', 'p.invoice_id')->where('p.journal_entry_id', $gl->journal_entry_id)
                    ->whereNull('p.deleted_at')->select('p.id', 'p.reference', 'p.payment_number', 'p.invoice_id', 'p.customer_id', 'c.code as customer_code', 'c.name as customer_name', 'i.invoice_number')->first();
                $paymentRefs = collect([$glRef, $this->normalize($payment?->reference), $this->normalize($payment?->payment_number)])->filter();
                $referenceMatch = $statementRef !== '' && $paymentRefs->contains($statementRef);
                $referenceConflict = $statementRef !== '' && $paymentRefs->isNotEmpty() && ! $referenceMatch;
                $invoiceMatch = $invoiceReferences->isNotEmpty() && $invoiceReferences->contains($this->normalize($payment?->invoice_number));
                $importMapping = $statement->import->mapping ?? [];
                $mappedCustomerHeader = $importMapping['customer_code'] ?? null;
                $statementCustomerCode = $this->normalize($statement->customer_code
                    ?: ($mappedCustomerHeader ? ($statement->raw_data[$mappedCustomerHeader] ?? null) : null)
                    ?: $this->rawValue($statement->raw_data ?? [], ['customercode']));
                $customerCodeMatch = $statementCustomerCode !== '' && $statementCustomerCode === $this->normalize($payment?->customer_code);
                $customerName = $this->normalize($payment?->customer_name);
                $payerText = $this->normalize(($statement->customer_reference ?? '').' '.$statement->description);
                $customerNameMatch = $customerName !== '' && str_contains($payerText, $customerName);
                $customerConflict = $statementCustomerCode !== '' && $payment?->customer_code && ! $customerCodeMatch;
                $score = $amountScore + ($referenceMatch ? 0.25 : 0) + ($invoiceMatch ? 0.15 : 0)
                    + (($customerCodeMatch || $customerNameMatch) ? 0.10 : 0) + $dateScore
                    - ($referenceConflict ? 0.15 : 0) - ($customerConflict ? 0.20 : 0);

                if ($amountScore === 0 && ! $referenceMatch && ! $invoiceMatch && ! $customerCodeMatch && ! $customerNameMatch) {
                    return null;
                }

                return ['line' => $gl, 'score' => max(0, $score),
                    'allocation' => abs($glRemaining) < abs((float) $statement->residual_amount) ? $glRemaining : (float) $statement->residual_amount,
                    'reasons' => ['exact_amount' => $amountScore > 0, 'reference_match' => $referenceMatch, 'reference_conflict' => $referenceConflict,
                        'invoice_match' => $invoiceMatch, 'customer_code_match' => $customerCodeMatch, 'customer_name_match' => $customerNameMatch,
                        'customer_conflict' => $customerConflict, 'date_gap_days' => $dateGap,
                        'payment_id' => $payment?->id, 'payment_number' => $payment?->payment_number,
                        'invoice_id' => $payment?->invoice_id, 'invoice_number' => $payment?->invoice_number,
                        'customer_code' => $payment?->customer_code, 'customer_name' => $payment?->customer_name]];
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

        $this->audit->record('suggestions_generated', ['actor_id' => $actorId, 'bank_account_id' => $import->bank_account_id, 'import_id' => $import->id, 'after_state' => ['suggestions_created' => $created]]);

        return ['suggestions_created' => $created];
    }

    public function accept(array $data, int $userId): BankStatementMatch
    {
        return DB::transaction(function () use ($data, $userId) {
            $payloadHash = hash('sha256', json_encode(collect($data)->except('idempotency_key')->sortKeys()->all(), JSON_THROW_ON_ERROR));
            $operation = BankStatementOperation::where('operation', 'match_accept')->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($operation) {
                if (! hash_equals($operation->payload_hash, $payloadHash)) {
                    throw new MatchConflict('Idempotency key was already used with a different payload.');
                }
                if ($operation->status === 'completed' && $operation->response_id) {
                    return BankStatementMatch::findOrFail($operation->response_id);
                }
            }
            $operation ??= BankStatementOperation::create(['operation' => 'match_accept', 'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $payloadHash, 'actor_id' => $userId, 'status' => 'processing', 'correlation_id' => (string) \Illuminate\Support\Str::uuid()]);
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
            $tolerance = (float) $this->tolerances->for($statement->currency_code);
            if (abs($amount) - abs($statementRemaining) > $tolerance || abs($amount) - abs($glRemaining) > $tolerance) {
                throw new MatchConflict('Match amount exceeds the available statement or GL amount.');
            }
            $payment = DB::table('payments as p')->leftJoin('customers as c', 'c.id', '=', 'p.customer_id')
                ->leftJoin('invoices as i', 'i.id', '=', 'p.invoice_id')->where('p.journal_entry_id', $gl->journal_entry_id)
                ->whereNull('p.deleted_at')->select('p.id', 'p.payment_number', 'p.invoice_id', 'i.invoice_number', 'p.customer_id', 'c.code as customer_code', 'c.name as customer_name')->first();
            $match = BankStatementMatch::create(['bank_statement_transaction_id' => $statement->id, 'journal_entry_line_id' => $gl->id, 'matched_amount' => $amount, 'match_type' => 'manual', 'confidence' => null, 'match_reasons' => ['manual' => true, 'payment_id' => $payment?->id, 'payment_number' => $payment?->payment_number, 'invoice_id' => $payment?->invoice_id, 'invoice_number' => $payment?->invoice_number, 'customer_id' => $payment?->customer_id, 'customer_code' => $payment?->customer_code, 'customer_name' => $payment?->customer_name], 'review_reason' => $data['review_reason'], 'status' => 'accepted', 'accepted_by' => $userId, 'accepted_at' => now(), 'idempotency_key' => $data['idempotency_key']]);
            $this->refreshStatement($statement);
            $operation->update(['status' => 'completed', 'response_type' => BankStatementMatch::class, 'response_id' => $match->id, 'response' => ['match_id' => $match->id]]);
            $this->audit->record('match_accepted', ['actor_id' => $userId, 'bank_account_id' => $statement->bank_account_id, 'import_id' => $statement->import_id, 'statement_transaction_id' => $statement->id, 'match_id' => $match->id, 'reason' => $data['review_reason'], 'after_state' => ['journal_entry_line_id' => $gl->id, 'matched_amount' => $amount]], $operation->correlation_id);

            return $match;
        });
    }

    public function remove(string $matchId, int $actorId): void
    {
        DB::transaction(function () use ($matchId, $actorId) {
            $match = BankStatementMatch::lockForUpdate()->findOrFail($matchId);
            if ($match->reconciliation_id !== null) {
                throw new MatchConflict('A completed reconciliation match cannot be removed.');
            }
            $statement = BankStatementTransaction::lockForUpdate()->findOrFail($match->bank_statement_transaction_id);
            $match->update(['status' => 'reversed', 'version' => $match->version + 1]);
            $this->refreshStatement($statement);
            $this->audit->record('match_removed', ['actor_id' => $actorId, 'bank_account_id' => $statement->bank_account_id, 'import_id' => $statement->import_id, 'statement_transaction_id' => $statement->id, 'match_id' => $match->id, 'before_state' => ['status' => 'accepted', 'matched_amount' => $match->matched_amount], 'after_state' => ['status' => 'reversed']]);
        });
    }

    public function acceptGroup(array $allocations, string $idempotencyKey, int $actorId): array
    {
        return DB::transaction(function () use ($allocations, $idempotencyKey, $actorId) {
            $matches = [];
            foreach ($allocations as $index => $allocation) {
                $matches[] = $this->accept([
                    ...$allocation,
                    'idempotency_key' => $idempotencyKey.':'.$index,
                ], $actorId);
            }

            return $matches;
        });
    }

    public function bulkAccept(string $importId, string $idempotencyKey, int $actorId): array
    {
        $proposals = BankStatementMatch::query()->with('transaction')
            ->whereHas('transaction', fn ($query) => $query->where('import_id', $importId))
            ->where('status', 'proposed')->where('confidence', '>=', 0.95)->orderBy('bank_statement_transaction_id')->orderByDesc('confidence')->get()
            ->filter(fn ($proposal) => ! ($proposal->match_reasons['ambiguous'] ?? false))
            ->groupBy('bank_statement_transaction_id')->filter(fn ($items) => $items->count() === 1)->flatten();
        $allocations = $proposals->map(fn ($proposal) => [
            'bank_statement_transaction_id' => $proposal->bank_statement_transaction_id,
            'journal_entry_line_id' => $proposal->journal_entry_line_id,
            'matched_amount' => (float) $proposal->matched_amount,
            'review_reason' => 'Accepted unique high-confidence deterministic suggestion.',
        ])->values()->all();

        return ['accepted' => count($this->acceptGroup($allocations, $idempotencyKey, $actorId))];
    }

    /**
     * Issue #841 (BK9): public so every accept path (statement controller or
     * auto-match) lands the statement row in the same residual/status state
     * the reconciliation engine's validate/complete read.
     */
    public function refreshStatement(BankStatementTransaction $statement): void
    {
        $matched = (float) BankStatementMatch::where('bank_statement_transaction_id', $statement->id)->where('status', 'accepted')->sum('matched_amount');
        $residual = (float) $statement->amount - $matched;
        $tolerance = (float) $this->tolerances->for($statement->currency_code);
        $status = abs($residual) <= $tolerance ? 'matched' : (abs($matched) > $tolerance ? 'partially_matched' : 'unmatched');
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

    private function rawValue(array $rawData, array $normalizedHeaders): ?string
    {
        foreach ($rawData as $header => $value) {
            $normalizedHeader = preg_replace('/[^a-z0-9]/i', '', strtolower((string) $header));
            if (in_array($normalizedHeader, $normalizedHeaders, true) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}
