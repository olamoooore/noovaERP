<?php

namespace Modules\PaymentGateway\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosPayment;
use Modules\POS\Services\PosPostingService;
use Modules\Sales\Models\SalesInvoiceLink;
use Modules\Sales\Services\SalesOrderService;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class PaymentTransactionCompletionService
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly PosPostingService $posPostingService,
        private readonly SalesOrderService $salesOrderService,
    ) {}

    public function complete(PaymentTransaction $transaction, ?string $paymentMethod = null): PaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $paymentMethod) {
            $transaction = PaymentTransaction::whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            $invoice = $transaction->invoice;

            if (!$invoice && !empty($transaction->metadata['pos_order_id'])) {
                $posOrder = PosOrder::find($transaction->metadata['pos_order_id']);

                if (!$posOrder) {
                    throw new \RuntimeException('No invoice or POS order found for transaction.');
                }

                $this->completePosOrderPayment($transaction, $posOrder, $paymentMethod);
                return $transaction->fresh();
            }

            if (!$invoice) {
                throw new \RuntimeException('No invoice or POS order found for transaction.');
            }

            $existingPayment = Payment::where('reference', $transaction->transaction_id)->first();

            if (!$existingPayment) {
                $this->invoiceService->recordPayment($invoice, [
                    'payment_date' => now(),
                    'amount' => (float) $transaction->amount,
                    'method' => $this->normalizeInvoicePaymentMethod($paymentMethod ?? $transaction->payment_method),
                    'reference' => $transaction->transaction_id,
                    'notes' => "Online payment via {$transaction->gateway->gateway_name}",
                ]);
            }

            $transaction->update([
                'customer_id' => $invoice->customer_id,
                'processed_at' => $transaction->processed_at ?? now(),
            ]);

            return $transaction->fresh();
        });
    }

    private function completePosOrderPayment(PaymentTransaction $transaction, PosOrder $posOrder, ?string $paymentMethod = null): void
    {
        $existingPosPayment = PosPayment::where('pos_order_id', $posOrder->id)
            ->where('reference', $transaction->transaction_id)
            ->first();

        if (!$existingPosPayment) {
            PosPayment::create([
                'pos_order_id' => $posOrder->id,
'method' => $this->normalizePosPaymentMethod($paymentMethod),
                'amount' => $transaction->amount,
                'reference' => $transaction->transaction_id,
                'notes' => "Online payment via {$transaction->gateway->gateway_name}",
                'received_by' => TenantUserHelper::requireTenantUserId($posOrder->created_by),
            ]);
        }

        $entry = $this->posPostingService->postPaidOrder(
            $posOrder->loadMissing('items.variant', 'items.product', 'terminal.warehouse'),
            (float) $transaction->amount,
            $this->normalizePosPaymentMethod($paymentMethod ?? $transaction->payment_method),
        );

        $posOrder->update([
            'status' => 'paid',
            'paid_at' => $posOrder->paid_at ?? now(),
            'journal_entry_id' => $entry->id,
            'receipt_number' => $posOrder->receipt_number ?: PosOrder::generateReceiptNumber(),
        ]);

        $invoice = $this->createOrGetInvoiceFromPosOrder($posOrder, $paymentMethod);

        $transaction->update([
            'invoice_id' => $invoice?->id,
            'customer_id' => $invoice?->customer_id,
            'processed_at' => $transaction->processed_at ?? now(),
        ]);
    }

    private function createOrGetInvoiceFromPosOrder(PosOrder $posOrder, ?string $paymentMethod = null): ?Invoice
    {
        $customerId = $posOrder->customer_id;

        if (!$customerId) {
            $walkInCustomer = \App\Models\Customer::firstOrCreate(
                ['email' => 'walkin@system.local'],
                [
                    'name' => 'Walk-in Customer',
                    'phone' => null,
                    'address' => null,
                    'notes' => 'System customer for walk-in POS transactions',
                ]
            );

            $customerId = $walkInCustomer->id;
            $posOrder->update(['customer_id' => $customerId]);
        }

        $salesOrder = $this->salesOrderService->createFromPosOrder($posOrder);

        if ($salesOrder->isInvoiced()) {
            $link = SalesInvoiceLink::where('sales_order_id', $salesOrder->id)
                ->where('link_state', 'active')
                ->first();

            if ($link) {
                return Invoice::find($link->invoice_id);
            }
        }

        if ($salesOrder->isDraft()) {
            $salesOrder = $this->salesOrderService->confirmOrder($salesOrder);
        }

        if ($salesOrder->isConfirmed()) {
            $salesOrder = $this->salesOrderService->allocateOrder($salesOrder);
        }

        if ($salesOrder->isAllocated()) {
            $salesOrder = $this->salesOrderService->fulfilOrder($salesOrder);
        }

        $invoice = $this->salesOrderService->createInvoice($salesOrder);

        // Mark as paid — the POS channel-level GL was already posted by
        // PosPostingService::postPaidOrder(). We use recordChannelPayment()
        // so the invoice balance is reconciled without a duplicate payment GL entry.
        $this->invoiceService->recordChannelPayment($invoice, [
            'payment_date' => now(),
            'method' => $this->normalizePosPaymentMethod($paymentMethod),
            'reference' => "POS-{$posOrder->order_number}",
            'amount' => (float) $posOrder->total,
            'notes' => "POS order {$posOrder->order_number} (pre-paid)",
            'received_by' => TenantUserHelper::requireTenantUserId($posOrder->created_by),
        ]);

        $posOrder->update(['invoice_id' => $invoice->id]);

        return $invoice;
    }

    private function normalizeInvoicePaymentMethod(?string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'bank', 'bank_transfer' => 'bank_transfer',
            'check', 'cheque' => 'check',
            'other', 'paypal', 'hitpay' => 'other',
            default => 'card',
        };
    }

    private function normalizePosPaymentMethod(?string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'cash' => 'cash',
            'bank', 'bank_transfer' => 'other',
            'other', 'paypal', 'hitpay' => 'other',
            default => 'card',
        };
    }
}
