<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\BankAccount;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

class BillPaymentService
{
    public function __construct(
        private readonly PostingEngineInterface $postingEngine,
    ) {}

    public function recordPayment(array $data, ?int $userId = null): BillPayment
    {
        return DB::transaction(function () use ($data, $userId) {
            $vendor = Vendor::findOrFail($data['vendor_id']);
            $bill = null;

            if (!empty($data['bill_id'])) {
                $bill = Bill::whereKey($data['bill_id'])->lockForUpdate()->firstOrFail();

                if ((int) $bill->vendor_id !== (int) $vendor->id) {
                    throw new \InvalidArgumentException('Bill does not belong to the specified vendor.');
                }

                if ((float) $data['amount'] > (float) $bill->balance) {
                    throw new \InvalidArgumentException('Payment amount exceeds bill balance.');
                }
            }

            $payment = BillPayment::create([
                'payment_number' => $data['payment_number'] ?? BillPayment::generatePaymentNumber(),
                'vendor_id' => $vendor->id,
                'bill_id' => $bill?->id,
                'payment_date' => $data['payment_date'],
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'],
                'bank_account_id' => $data['bank_account_id'] ?? null,
                'reference' => $data['reference'] ?? null,
                'memo' => $data['memo'] ?? null,
                'created_by' => $data['created_by'] ?? $userId,
            ]);

            $result = $this->postPayment($payment, $vendor, $userId ?? $payment->created_by);

            if (!$result->success) {
                throw new \RuntimeException($result->error ?? 'Failed to post bill payment.');
            }

            $payment->update(['journal_entry_id' => $result->journalEntryId]);

            if ($bill) {
                $this->applyBillPayment($bill, (float) $payment->amount);
            }

            $vendor->updateSpending();

            return $payment->fresh(['vendor', 'bill', 'journalEntry']);
        });
    }

    public function updateDraftPayment(BillPayment $payment, array $data): BillPayment
    {
        if ($payment->journal_entry_id) {
            throw new \RuntimeException('Cannot update payment that has been posted to journal.');
        }

        return DB::transaction(function () use ($payment, $data) {
            $originalBillId = $payment->bill_id;
            $originalVendorId = $payment->vendor_id;

            $payment->update([
                'payment_date' => $data['payment_date'] ?? $payment->payment_date,
                'amount' => $data['amount'] ?? $payment->amount,
                'payment_method' => $data['payment_method'] ?? $payment->payment_method,
                'reference' => array_key_exists('reference', $data) ? $data['reference'] : $payment->reference,
                'memo' => array_key_exists('memo', $data) ? $data['memo'] : $payment->memo,
            ]);

            if ($originalVendorId) {
                Vendor::find($originalVendorId)?->updateSpending();
            }

            if ($originalBillId) {
                Bill::find($originalBillId)?->refresh();
            }

            return $payment->fresh(['vendor', 'bill']);
        });
    }

    public function deleteDraftPayment(BillPayment $payment): void
    {
        if ($payment->journal_entry_id) {
            throw new \RuntimeException('Cannot delete payment that has been posted to journal.');
        }

        DB::transaction(function () use ($payment) {
            $vendorId = $payment->vendor_id;
            $payment->delete();
            Vendor::find($vendorId)?->updateSpending();
        });
    }

    private function postPayment(BillPayment $payment, Vendor $vendor, ?int $userId): \App\Accounting\DTOs\PostingResult
    {
        $bankGlAccountId = null;

        if ($payment->bank_account_id) {
            $bankAccount = BankAccount::find($payment->bank_account_id);
            $bankGlAccountId = $bankAccount?->gl_account_id;
        }

        $cashMappingKey = match ($payment->payment_method) {
            'bank' => 'bank',
            default => 'cash',
        };

        return $this->postingEngine->post(new PostingRequest(
            documentType: 'bill_payment',
            documentId: $payment->id,
            postingDate: $payment->payment_date instanceof \DateTimeInterface
                ? $payment->payment_date->format('Y-m-d')
                : (string) $payment->payment_date,
            description: "Bill Payment #{$payment->payment_number} - {$vendor->name}",
            lines: [
                new PostingRequestLine(
                    accountMappingKey: 'accounts_payable',
                    accountId: null,
                    debit: (float) $payment->amount,
                    credit: 0,
                    description: "Bill payment {$payment->payment_number}",
                ),
                new PostingRequestLine(
                    accountMappingKey: $cashMappingKey,
                    accountId: $bankGlAccountId,
                    debit: 0,
                    credit: (float) $payment->amount,
                    description: "Bill payment {$payment->payment_number} - {$vendor->name}",
                ),
            ],
            reference: $payment->payment_number,
            userId: $userId,
        ));
    }

    private function applyBillPayment(Bill $bill, float $amount): void
    {
        Bill::whereKey($bill->id)->increment('paid_amount', $amount);
        $bill->refresh();

        $newBalance = (float) $bill->total - (float) $bill->paid_amount;

        Bill::whereKey($bill->id)->update([
            'balance' => $newBalance,
            'status' => $newBalance <= 0
                ? 'paid'
                : ((float) $bill->paid_amount > 0 ? 'partial' : $bill->status),
        ]);
    }
}
