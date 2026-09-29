<?php

declare(strict_types=1);

namespace Modules\PaymentGateway\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\Services\AccountRoleResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\PaymentGateway\Models\PaymentSettlement;
use Modules\PaymentGateway\Models\PaymentTransaction;

/**
 * FR-PAY-06 / P-04a settlement reconciliation (platform-owned, channel
 * agnostic — any module whose gateway receipts post to Gateway Receivable
 * consumes it; the Storefront completion is the first writer).
 *
 * Ingests settlement rows (transaction_id, gross, fee, net, currency,
 * settled_at, optional batch reference) and for each MATCHED row posts the
 * money movement out of clearing:
 *
 *   DR cash/bank        net
 *   DR payment fees     fee   (platform 'general_expense' precedent, explicit
 *                              account supported via gateway config
 *                              fee_expense_account_id)
 *   CR gateway receivable  gross
 *
 * Rows are classified, never guessed: variance (gross != captured amount),
 * orphan (no such transaction), already_settled (idempotent file replay),
 * offline (internal settlement anchors are not gateway money), and
 * clearing_unconfigured (merchant has not assigned Gateway Receivable — the
 * credit leg would land nowhere; the sale itself fell back to direct cash,
 * so there is nothing to reconcile). One settlement row per transaction
 * (DB-enforced unique) and an engine journal link per settlement row make
 * every replay converge without a second entry.
 */
final class SettlementReconciliationService
{
    public function __construct(
        private readonly PostingEngineInterface $postingEngine,
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function ingestRows(array $rows, ?string $batchReference = null): array
    {
        $report = [
            'matched' => [], 'variance' => [], 'orphan' => [],
            'already_settled' => [], 'offline' => [], 'invalid' => [],
            'clearing_unconfigured' => [],
        ];

        foreach (array_values($rows) as $index => $row) {
            $lineNo = (int) $index + 1;
            $result = $this->ingestRow($row, $lineNo, $batchReference);
            $report[$result['bucket']][] = $result;
        }

        return $report;
    }

    /** @return array{bucket:string,row:int,transaction_id?:string,reason?:string,mixed} */
    private function ingestRow(array $row, int $lineNo, ?string $batchReference): array
    {
        $txnId = trim((string) ($row['transaction_id'] ?? ''));
        $grossRaw = $row['gross'] ?? null;
        $fee = round((float) ($row['fee'] ?? 0), 2);
        $currency = strtoupper(trim((string) ($row['currency'] ?? '')));

        if ($txnId === '' || $grossRaw === null || $grossRaw === '') {
            return ['bucket' => 'invalid', 'row' => $lineNo, 'transaction_id' => $txnId, 'reason' => 'missing_fields'];
        }

        $gross = round((float) str_replace(',', '', (string) $grossRaw), 2);
        $net = isset($row['net']) && $row['net'] !== ''
            ? round((float) str_replace(',', '', (string) $row['net']), 2)
            : round($gross - $fee, 2);

        if ($gross <= 0 || $fee < 0 || $net < 0 || abs(($gross - $fee) - $net) > 0.005) {
            return ['bucket' => 'invalid', 'row' => $lineNo, 'transaction_id' => $txnId, 'reason' => 'amounts_inconsistent'];
        }

        $transaction = PaymentTransaction::query()->where('transaction_id', $txnId)->first();

        if ($transaction === null) {
            // Orphan payout: the gateway paid out something this tenant has
            // no captured transaction for. Reported, never booked.
            return ['bucket' => 'orphan', 'row' => $lineNo, 'transaction_id' => $txnId, 'reason' => 'no_such_transaction'];
        }

        $isOffline = (string) ($transaction->gateway?->gateway_name ?? '') === 'offline'
            || str_starts_with($txnId, 'offline:');
        if ($isOffline) {
            return ['bucket' => 'offline', 'row' => $lineNo, 'transaction_id' => $txnId, 'reason' => 'internal_anchor_not_gateway_money'];
        }

        if ($transaction->status !== 'completed') {
            return ['bucket' => 'invalid', 'row' => $lineNo, 'transaction_id' => $txnId, 'reason' => 'transaction_not_completed'];
        }

        if ($currency !== '' && $currency !== strtoupper((string) $transaction->currency)) {
            return ['bucket' => 'invalid', 'row' => $lineNo, 'transaction_id' => $txnId, 'reason' => 'currency_mismatch'];
        }

        if (PaymentSettlement::query()->where('payment_transaction_id', $transaction->id)->exists()) {
            return ['bucket' => 'already_settled', 'row' => $lineNo, 'transaction_id' => $txnId, 'reason' => 'file_replay_idempotent'];
        }

        if (abs($gross - (float) $transaction->amount) > 0.005) {
            // Fee variance against the captured amount (or a gateway-side
            // refund reducing the payout): refuse to invent a number.
            return ['bucket' => 'variance', 'row' => $lineNo, 'transaction_id' => $txnId, 'reason' => 'gross_not_captured_amount', 'gross' => $gross, 'captured' => (float) $transaction->amount];
        }

        try {
            app(AccountRoleResolver::class)->resolve('gateway_receivable');
        } catch (\Throwable) {
            return ['bucket' => 'clearing_unconfigured', 'row' => $lineNo, 'transaction_id' => $txnId, 'reason' => 'gateway_receivable_not_assigned'];
        }

        $feeAccountId = (int) ($transaction->gateway?->config['fee_expense_account_id'] ?? 0) ?: null;

        $settlement = DB::transaction(function () use ($transaction, $batchReference, $txnId, $gross, $fee, $net, $row, $currency, $feeAccountId) {
            $settlement = PaymentSettlement::create([
                'payment_transaction_id' => (int) $transaction->id,
                'settlement_reference' => $batchReference,
                'gateway_txn_id' => $txnId,
                'gross_amount' => $gross,
                'fee_amount' => $fee,
                'net_amount' => $net,
                'currency' => $currency !== '' ? $currency : strtoupper((string) $transaction->currency),
                'settled_at' => isset($row['settled_at']) && $row['settled_at'] !== '' ? $row['settled_at'] : now()->toDateString(),
            ]);

            $lines = [
                new PostingRequestLine(
                    accountMappingKey: 'cash',
                    accountId: null,
                    debit: $net,
                    credit: 0,
                    description: 'Settlement received',
                    reference: $txnId,
                ),
            ];
            if ($fee > 0) {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'general_expense',
                    accountId: $feeAccountId,
                    debit: $fee,
                    credit: 0,
                    description: 'Payment gateway fee',
                    reference: $txnId,
                );
            }
            $lines[] = new PostingRequestLine(
                accountMappingKey: 'gateway_receivable',
                accountId: null,
                debit: 0,
                credit: $gross,
                description: 'Clearing gateway receivable',
                reference: $txnId,
            );

            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'payment_settlement',
                documentId: (int) $settlement->id,
                postingDate: now()->toDateString(),
                description: "Settlement for {$txnId} (gross {$gross}, fee {$fee}, net {$net})",
                lines: $lines,
                reference: 'SETTLE-'.$txnId,
                userId: TenantUserHelper::resolveTenantUserId(),
                legalEntityId: request()?->attributes->get('legal_entity_id'),
                entityBookId: request()->attributes->get('entity_book_id') ?? null,
            ));

            if (! $result->success) {
                throw new \RuntimeException("Settlement GL posting failed for {$txnId}: {$result->error}");
            }

            $settlement->update(['journal_entry_id' => $result->journalEntryId ?? $result->journalEntry->id]);

            return $settlement;
        });

        Log::info('Payment settlement reconciled', [
            'transaction_id' => $txnId,
            'settlement_id' => $settlement->id,
            'journal_entry_id' => $settlement->journal_entry_id,
        ]);

        return [
            'bucket' => 'matched',
            'row' => $lineNo,
            'transaction_id' => $txnId,
            'settlement_id' => (int) $settlement->id,
            'journal_entry_id' => $settlement->journal_entry_id,
        ];
    }

    /**
     * CSV ingest: header line required with transaction_id + gross
     * (fee/net/currency/settled_at optional; net defaults to gross-fee).
     *
     * @return array<int,array<string,mixed>>
     */
    public function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \InvalidArgumentException("Settlement file not readable: {$path}");
        }

        $header = fgetcsv($handle);
        if (! is_array($header)) {
            fclose($handle);

            return [];
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);

        $rows = [];
        while (($cells = fgetcsv($handle)) !== false) {
            if ($cells === [null] || $cells === false) {
                continue;
            }
            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = $cells[$i] ?? null;
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
