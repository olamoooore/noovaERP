<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\ReversalRequest;
use App\Models\BankAccount;
use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteRefund;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Contact\src\Models\ContactBankAccount;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * Issue #528 (SALES-ODOO): pay a posted credit note's remaining credit out to
 * the customer — Odoo's "Register Payment" on a credit note (outbound refund).
 *
 * Remaining credit is the #525/#495 figure from
 * CustomerCreditNoteOutcomeService::remainingCredit(): credit the note's own
 * invoice could not absorb, less live applications to other invoices, less
 * live refunds. A refund:
 *  - records an outbound customer payment (a payments row with a negative
 *    amount, the shape the existing refund corrections use, so the AR
 *    subledger view picks it up as an AR debit);
 *  - posts Dr Accounts Receivable / Cr Bank through the PostingEngine in the
 *    note's legal entity;
 *  - releases the refunded credit from the customer credit balance
 *    (InvoiceService, credited_amount parked on the note's invoice, #510).
 *
 * Voiding a refund reverses its journal through the PostingEngine, books the
 * mirror payment row and puts the credit back. Refused (InvalidArgumentException):
 * amounts beyond the remaining credit, another legal entity's context, a bank
 * account or currency other than the note's currency, and foreign-currency
 * notes (an FX settlement for refunds is not implemented).
 */
class CustomerCreditNoteRefundService
{
    public function __construct(
        private readonly PostingEngineInterface $postingEngine,
        private readonly InvoiceService $invoiceService,
        private readonly CustomerCreditNoteOutcomeService $outcomes,
        private readonly PaymentPostingService $paymentPosting,
        private readonly LegalEntityContextResolver $entityResolver,
    ) {}

    /**
     * @param  array{amount: float|int|string, bank_account_id?: int|null, customer_bank_account_id?: string|null, method?: string|null, refund_date?: string|null, reference?: string|null, notes?: string|null, currency_code?: string|null}  $data
     */
    public function refund(CustomerCreditNote $creditNote, array $data): CustomerCreditNoteRefund
    {
        $actorId = TenantUserHelper::requireTenantUserId(Auth::id());

        return DB::transaction(function () use ($creditNote, $data, $actorId) {
            // Lock the note so concurrent refunds/applications cannot both spend its credit.
            $creditNote = CustomerCreditNote::whereKey($creditNote->id)->lockForUpdate()->firstOrFail();

            if ($creditNote->status !== 'posted') {
                throw new \InvalidArgumentException(
                    "Credit note {$creditNote->credit_note_number} is '{$creditNote->status}' — only posted credit notes can be refunded."
                );
            }

            $origin = $creditNote->invoice_id
                ? Invoice::whereKey($creditNote->invoice_id)->lockForUpdate()->first()
                : null;
            if (! $origin) {
                throw new \InvalidArgumentException("Credit note {$creditNote->credit_note_number} has no source invoice holding its credit.");
            }

            $amount = round((float) $data['amount'], 2);
            if ($amount <= 0) {
                throw new \InvalidArgumentException('The refund amount must be greater than zero.');
            }

            // The credit sits in the note's entity books; it can only be paid
            // out from that entity's context.
            $noteEntityId = $creditNote->legal_entity_id ? (int) $creditNote->legal_entity_id : null;
            $contextEntityId = $this->entityResolver->fromRequestContext();
            if ($noteEntityId !== null && $contextEntityId !== null && $noteEntityId !== $contextEntityId) {
                throw new \InvalidArgumentException(
                    "Credit note {$creditNote->credit_note_number} belongs to another legal entity than the one you are working in."
                );
            }

            $baseCurrency = strtoupper((string) ($creditNote->base_currency_code
                ?: $origin->base_currency_code
                ?: DocumentCurrencyResolver::baseFor(null, $creditNote->customer_id)));
            $currency = strtoupper((string) ($creditNote->currency_code ?: $baseCurrency));

            if ($currency !== $baseCurrency) {
                throw new \InvalidArgumentException(
                    "Credit note {$creditNote->credit_note_number} is in {$currency}; refunds of foreign-currency credit notes are not supported."
                );
            }

            if (! empty($data['currency_code']) && strtoupper((string) $data['currency_code']) !== $currency) {
                throw new \InvalidArgumentException(
                    "The credit note's credit is in {$currency}; it cannot be refunded in {$data['currency_code']}."
                );
            }

            $bankAccountId = ! empty($data['bank_account_id']) ? (int) $data['bank_account_id'] : null;
            if ($bankAccountId !== null) {
                $bankAccount = BankAccount::find($bankAccountId);
                if (! $bankAccount || ! $bankAccount->is_active) {
                    throw new \InvalidArgumentException('The selected bank account is not an active bank account.');
                }
                if ($bankAccount->currency_code && strtoupper((string) $bankAccount->currency_code) !== $currency) {
                    throw new \InvalidArgumentException(
                        "Bank account {$bankAccount->account_name} is in {$bankAccount->currency_code}; the credit note's credit is in {$currency}."
                    );
                }
            }

            // #444: the refund can be paid to one of the customer's own bank
            // accounts (Contact Master contact_bank_accounts). It must belong
            // to this note's customer, be active and not removed, and be in
            // the refund currency when it names one.
            $customerBankAccountId = ! empty($data['customer_bank_account_id']) ? (string) $data['customer_bank_account_id'] : null;
            if ($customerBankAccountId !== null) {
                $payeeContactId = $creditNote->customer?->core_contact_uuid;
                $payee = $payeeContactId
                    ? ContactBankAccount::query()
                        ->where('contact_id', $payeeContactId)
                        ->whereKey($customerBankAccountId)
                        ->first()
                    : null;
                if (! $payee || ! $payee->is_active) {
                    throw new \InvalidArgumentException("The selected customer bank account is not an active bank account of this credit note's customer.");
                }
                if ($payee->currency && strtoupper((string) $payee->currency) !== $currency) {
                    throw new \InvalidArgumentException(
                        "The customer's bank account is in {$payee->currency}; the credit note's credit is in {$currency}."
                    );
                }
            }

            $available = $this->outcomes->remainingCredit($creditNote);
            if ($amount > $available + 0.005) {
                throw new \InvalidArgumentException(
                    "The refund ({$amount}) exceeds the credit note's remaining credit ({$available})."
                );
            }

            $method = (string) ($data['method'] ?? 'bank_transfer');
            try {
                [$cashMappingKey, $cashAccountId] = $this->paymentPosting->resolveCashAccount($bankAccountId, $method);
            } catch (\RuntimeException $e) {
                throw new \InvalidArgumentException($e->getMessage(), 0, $e);
            }

            $entityBookId = $creditNote->entity_book_id
                ? (int) $creditNote->entity_book_id
                : ($noteEntityId ? $this->entityResolver->resolveBookId($noteEntityId) : null);
            $refundDate = (string) ($data['refund_date'] ?? now()->toDateString());

            $payment = Payment::create([
                'customer_id' => $creditNote->customer_id,
                'payment_date' => $refundDate,
                'amount' => -$amount, // outbound: negative, like the refund corrections
                'method' => $method,
                'reference' => $data['reference'] ?? "Refund: {$creditNote->credit_note_number}",
                'notes' => $data['notes'] ?? "Refund of credit note {$creditNote->credit_note_number}",
                'bank_account_id' => $bankAccountId,
                'received_by' => $actorId,
                'payment_status' => 'refunded',
                'currency' => $currency,
                'base_currency_code' => $baseCurrency,
                'correction_type' => 'refund',
                'refunded_at' => now(),
                'legal_entity_id' => $noteEntityId,
                'entity_book_id' => $entityBookId,
            ]);

            $refund = CustomerCreditNoteRefund::create([
                'credit_note_id' => $creditNote->id,
                'payment_id' => $payment->id,
                'amount' => $amount,
                'currency_code' => $currency,
                'bank_account_id' => $bankAccountId,
                'customer_bank_account_id' => $customerBankAccountId,
                'created_by' => $actorId,
            ]);

            // Dr AR (the customer credit is paid out) / Cr Bank.
            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'payment',
                documentId: $payment->id,
                postingDate: $refundDate,
                description: "Refund of credit note {$creditNote->credit_note_number}"
                    .($creditNote->customer ? ' - '.$creditNote->customer->name : ''),
                lines: [
                    new PostingRequestLine(
                        accountMappingKey: 'accounts_receivable',
                        accountId: null,
                        debit: $amount,
                        credit: 0,
                        description: "Customer credit refunded - {$creditNote->credit_note_number}",
                    ),
                    new PostingRequestLine(
                        accountMappingKey: $cashMappingKey,
                        accountId: $cashAccountId,
                        debit: 0,
                        credit: $amount,
                        description: "Refund {$payment->payment_number} - {$creditNote->credit_note_number}",
                    ),
                ],
                reference: $payment->payment_number,
                userId: $actorId,
                legalEntityId: $noteEntityId,
                entityBookId: $entityBookId,
            ));

            if (! $result->success) {
                throw new \RuntimeException('Failed to post the refund: '.$result->error);
            }

            $payment->update(['journal_entry_id' => $result->journalEntryId]);
            $refund->update(['journal_entry_id' => $result->journalEntryId]);

            $this->invoiceService->releaseRefundedCustomerCredit(
                $origin,
                $amount,
                "Credit note {$creditNote->credit_note_number} refunded ({$payment->payment_number})",
            );

            // #901 (PC7): a customer refund is a sales event — outbox row
            // written inside this transaction, published after commit.
            app(\Modules\Sales\Services\SalesDomainEventEmitter::class)
                ->emitPaymentRefunded($payment, null);

            return $refund->fresh(['payment', 'customerBankAccount']);
        });
    }

    /**
     * Void a refund: reverse its journal through the PostingEngine, book the
     * mirror payment row (so the AR subledger nets to zero for the pair) and
     * put the credit back on the note.
     */
    public function voidRefund(CustomerCreditNote $creditNote, int $refundId, ?string $reason = null): CustomerCreditNoteRefund
    {
        $actorId = TenantUserHelper::requireTenantUserId(Auth::id());

        return DB::transaction(function () use ($creditNote, $refundId, $reason, $actorId) {
            $creditNote = CustomerCreditNote::whereKey($creditNote->id)->lockForUpdate()->firstOrFail();
            $refund = CustomerCreditNoteRefund::whereKey($refundId)
                ->where('credit_note_id', $creditNote->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($refund->voided_at !== null) {
                throw new \InvalidArgumentException('This refund has already been voided.');
            }

            $payment = $refund->payment_id ? Payment::whereKey($refund->payment_id)->lockForUpdate()->first() : null;
            $amount = round((float) $refund->amount, 2);

            $reversalEntryId = null;
            if ($refund->journal_entry_id) {
                $reversal = $this->postingEngine->reverse(new ReversalRequest(
                    journalEntryId: (int) $refund->journal_entry_id,
                    reversalDate: now()->toDateString(),
                    reason: "Void refund of credit note {$creditNote->credit_note_number}"
                        .($reason !== null && $reason !== '' ? ": {$reason}" : ''),
                    reasonCode: 'CORRECTION',
                    actorUserId: $actorId,
                    tenantId: tenant()->id ?? null,
                ));

                if (! $reversal->success) {
                    throw new \RuntimeException('Failed to reverse the refund journal: '.($reversal->error ?? 'unknown error'));
                }

                $reversalEntryId = $reversal->journalEntryId ?? $reversal->journalEntry?->id;
            }

            $mirror = null;
            if ($payment) {
                $mirror = Payment::create([
                    'customer_id' => $payment->customer_id,
                    'payment_date' => now()->toDateString(),
                    'amount' => $amount,
                    'method' => $payment->method,
                    'reference' => 'Reversal: '.$payment->payment_number,
                    'notes' => $reason,
                    'bank_account_id' => $payment->bank_account_id,
                    'received_by' => $actorId,
                    'payment_status' => 'reversed',
                    'currency' => $payment->currency,
                    'base_currency_code' => $payment->base_currency_code,
                    'original_payment_id' => $payment->id,
                    'correction_type' => 'reverse',
                    'correction_reason_text' => $reason,
                    'reversal_journal_entry_id' => $reversalEntryId,
                    'legal_entity_id' => $payment->legal_entity_id,
                    'entity_book_id' => $payment->entity_book_id,
                ]);

                $payment->update([
                    'payment_status' => 'reversed',
                    'refund_payment_id' => $mirror->id,
                    'reversal_journal_entry_id' => $reversalEntryId,
                ]);
            }

            $refund->update([
                'voided_at' => now(),
                'voided_by' => $actorId,
                'void_reason' => $reason,
                'reversal_journal_entry_id' => $reversalEntryId,
                'reversal_payment_id' => $mirror?->id,
            ]);

            $origin = $creditNote->invoice_id ? Invoice::whereKey($creditNote->invoice_id)->lockForUpdate()->first() : null;
            if ($origin) {
                $this->invoiceService->releaseRefundedCustomerCredit(
                    $origin,
                    -$amount,
                    "Refund of credit note {$creditNote->credit_note_number} voided",
                );
            }

            return $refund->fresh(['payment']);
        });
    }
}
