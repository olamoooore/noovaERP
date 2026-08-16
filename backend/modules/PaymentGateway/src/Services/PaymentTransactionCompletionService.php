<?php

namespace Modules\PaymentGateway\Services;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Ecommerce\Models\WebOrder;
use Modules\Ecommerce\Services\WebOrderPostingService;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\POS\Models\PosOrder;
use Modules\POS\Services\PosPaymentCompletionService;
use Modules\POS\Services\PosPostingService;
use Modules\Sales\Models\SalesInvoiceLink;
use Modules\Sales\Services\SalesOrderService;

class PaymentTransactionCompletionService
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly PosPostingService $posPostingService,
        private readonly PosPaymentCompletionService $posPaymentCompletionService,
        private readonly SalesOrderService $salesOrderService,
        private readonly WebOrderPostingService $webOrderPostingService,
    ) {}

    public function complete(PaymentTransaction $transaction, ?string $paymentMethod = null): PaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $paymentMethod) {
            $transaction = PaymentTransaction::whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            $invoice = $transaction->invoice;

            if (! $invoice && ! empty($transaction->metadata['pos_order_id'])) {
                $posOrder = PosOrder::find($transaction->metadata['pos_order_id']);

                if (! $posOrder) {
                    throw new \RuntimeException('No invoice or POS order found for transaction.');
                }

                $this->completePosOrderPayment($transaction, $posOrder, $paymentMethod);

                return $transaction->fresh();
            }

            if (! $invoice && (($transaction->metadata['type'] ?? null) === 'web_order' || ! empty($transaction->metadata['order_id']))) {
                $webOrder = $this->resolveWebOrder($transaction);

                if (! $webOrder) {
                    throw new \RuntimeException('No invoice, POS order, or web order found for transaction.');
                }

                $this->completeWebOrderPayment($transaction, $webOrder, $paymentMethod);

                return $transaction->fresh();
            }

            if (! $invoice) {
                throw new \RuntimeException('No invoice, POS order, or web order found for transaction.');
            }

            $existingPayment = Payment::where('reference', $transaction->transaction_id)->first();

            if (! $existingPayment) {
                $this->invoiceService->recordPayment($invoice, [
                    'payment_date' => now(),
                    'amount' => (float) $transaction->amount,
                    'method' => $this->normalizeInvoicePaymentMethod($paymentMethod ?? $transaction->payment_method),
                    'reference' => $transaction->transaction_id,
                    'notes' => "Online payment via {$transaction->gateway->gateway_name}",
                    'customer_id' => $transaction->customer_id ?? $invoice->customer_id,
                    'received_by' => $invoice->created_by,
                ]);
            }

            $transaction->update([
                'customer_id' => $transaction->customer_id ?? $invoice->customer_id,
                'processed_at' => $transaction->processed_at ?? now(),
                'error_message' => null,
            ]);

            return $transaction->fresh();
        });
    }

    private function completePosOrderPayment(PaymentTransaction $transaction, PosOrder $posOrder, ?string $paymentMethod = null): void
    {
        $this->posPaymentCompletionService->complete(
            $posOrder,
            (float) $transaction->amount,
            $this->normalizePosPaymentMethod($paymentMethod ?? $transaction->payment_method),
            $transaction->transaction_id,
            $posOrder->created_by,
            "Online payment via {$transaction->gateway->gateway_name}",
        );

        $invoice = $this->createOrGetInvoiceFromPosOrder($posOrder, $paymentMethod);

        $transaction->update([
            'invoice_id' => $invoice?->id,
            'customer_id' => $invoice?->customer_id,
            'processed_at' => $transaction->processed_at ?? now(),
            'error_message' => null,
        ]);
    }

    private function completeWebOrderPayment(PaymentTransaction $transaction, WebOrder $webOrder, ?string $paymentMethod = null): void
    {
        $webOrder->loadMissing('items.product', 'items.variant', 'invoice');

        if ($webOrder->status === WebOrder::STATUS_PENDING) {
            $webOrder->confirm();
        }

        if ($webOrder->payment_status === WebOrder::PAYMENT_STATUS_PENDING) {
            $webOrder->markPaid();
        }

        $invoice = $webOrder->invoice;
        if (! $invoice) {
            $invoice = $webOrder->convertToInvoice();
            $webOrder->refresh();
        }

        if (! $invoice) {
            throw new \RuntimeException('Invoice not created for web order.');
        }

        $entry = $this->webOrderPostingService->postPaidOrder(
            $webOrder->fresh(['items.product', 'items.variant']),
            (float) $transaction->amount,
            $this->normalizeWebOrderPaymentMethod($paymentMethod ?? $transaction->payment_method)
        );

        if (! $entry) {
            throw new \RuntimeException('Web order payment was confirmed but posting did not produce a journal entry.');
        }

        $this->updateWebOrderCustomerStats($webOrder->fresh());
        $this->awardWebOrderLoyaltyPoints($webOrder->fresh());

        if (class_exists(\Modules\EventManagement\Services\WebOrderTicketFulfillmentService::class)) {
            app(\Modules\EventManagement\Services\WebOrderTicketFulfillmentService::class)
                ->syncPaidWebOrder($webOrder->fresh(['items']));
        }

        $transaction->update([
            'invoice_id' => $invoice->id,
            'customer_id' => $webOrder->customer_id,
            'processed_at' => $transaction->processed_at ?? now(),
            'error_message' => null,
        ]);

        Log::info('Completed web order payment via canonical transaction completion service', [
            'transaction_id' => $transaction->transaction_id,
            'web_order_id' => $webOrder->id,
            'invoice_id' => $invoice->id,
            'journal_entry_id' => $entry->id,
        ]);
    }

    private function createOrGetInvoiceFromPosOrder(PosOrder $posOrder, ?string $paymentMethod = null): ?Invoice
    {
        $customerId = $posOrder->customer_id;

        if (! $customerId) {
            $walkInCustomer = \App\Models\Customer::firstOrCreate(
                ['email' => 'walkin@system.local'],
                [
                    'name' => 'Walk-in Customer',
                    'phone' => null,
                    'address' => null,
                    'notes' => 'System customer for walk-in POS transactions',
                ]
            );

            // ADR-003A CT-VEN-003: Route through Contact Master for canonical identity
            app(\App\Services\CustomerContactSyncService::class)->ensureOrganisationContact($walkInCustomer);

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

    private function resolveWebOrder(PaymentTransaction $transaction): ?WebOrder
    {
        $orderId = $transaction->metadata['order_id'] ?? null;

        if ($orderId) {
            $webOrder = WebOrder::find($orderId);
            if ($webOrder) {
                return $webOrder;
            }
        }

        if (! $transaction->transaction_id) {
            return null;
        }

        return WebOrder::where('payment_transaction_id', $transaction->transaction_id)->first();
    }

    private function awardWebOrderLoyaltyPoints(WebOrder $order): void
    {
        if (! $order->customer_id || ($order->loyalty_points_earned ?? 0) > 0) {
            return;
        }

        try {
            $result = app(\Modules\Loyalty\Services\PointsEarningService::class)->awardPoints(
                customerId: $order->customer_id,
                amount: (float) $order->subtotal,
                referenceType: 'web_order',
                referenceId: $order->id,
                description: "Points earned from web order {$order->order_number}"
            );

            if ($result['success'] ?? false) {
                $order->update([
                    'loyalty_points_earned' => $result['points_earned'] ?? 0,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to award loyalty points during web order completion', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function updateWebOrderCustomerStats(WebOrder $order): void
    {
        if (! $order->customer_id) {
            return;
        }

        try {
            $customer = \App\Models\Customer::find($order->customer_id);

            if (! $customer) {
                return;
            }

            $totalOrders = $customer->posOrders()->where('status', 'paid')->where('is_return', false)->count()
                + $customer->invoices()->whereIn('status', ['sent', 'partial', 'paid'])->count();
            $totalPurchases = $customer->posOrders()->where('status', 'paid')->where('is_return', false)->sum('total')
                + $customer->invoices()->whereIn('status', ['sent', 'partial', 'paid'])->sum('total');

            $customer->update([
                'total_orders' => $totalOrders,
                'total_purchases' => $totalPurchases,
                'last_interaction_at' => now(),
                'average_order_value' => $totalOrders > 0 ? $totalPurchases / $totalOrders : 0,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to update customer stats during web order completion', [
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'error' => $e->getMessage(),
            ]);
        }
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

    private function normalizeWebOrderPaymentMethod(?string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'bank', 'bank_transfer' => 'other',
            'cash' => 'cash',
            'other', 'paypal', 'hitpay' => 'other',
            default => 'card',
        };
    }
}
