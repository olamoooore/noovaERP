<?php

declare(strict_types=1);

namespace App\Platform\Notifications;

use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use Illuminate\Database\Eloquent\Model;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\SalesOrder;

/**
 * Builds the canonical reference block that goes into a notification payload.
 *
 * Notifications are the source of truth for "what is this about": the payload
 * lists the document plus the records it connects to, as typed
 * `{type, id, number|name}` references. The frontend resolves those to routes
 * in one place (src/lib/notificationLinks.ts), so a notification links to the
 * invoice / customer / product / quotation / delivery order / sales order it
 * concerns instead of relying on a single hardcoded id.
 */
class NotificationReferences
{
    /** Distinct products beyond this many are not worth listing as links. */
    private const MAX_PRODUCT_LINKS = 3;

    /** @return array{type: string, id: int|string, number?: string, name?: string}|null */
    private static function ref(string $type, mixed $id, ?string $number = null, ?string $name = null): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }

        $ref = ['type' => $type, 'id' => $id];
        if ($number !== null && $number !== '') {
            $ref['number'] = $number;
        }
        if ($name !== null && $name !== '') {
            $ref['name'] = $name;
        }

        return $ref;
    }

    /**
     * @param  array<int, array|null>  $refs
     * @return array<int, array>
     */
    private static function compact(array $refs): array
    {
        return array_values(array_filter($refs, fn ($ref) => $ref !== null));
    }

    /**
     * Up to MAX_PRODUCT_LINKS distinct products on the document — a couple of
     * items are useful to jump to, a fifty-line order is not.
     *
     * @return array<int, array>
     */
    private static function productRefs(mixed $linesRelation): array
    {
        try {
            // reorder() is required: the lines relation carries a default
            // ORDER BY, and Postgres rejects SELECT DISTINCT with an ORDER BY
            // expression that is not in the select list.
            $ids = $linesRelation->whereNotNull('product_id')
                ->distinct()
                ->reorder('product_id')
                ->limit(self::MAX_PRODUCT_LINKS + 1)
                ->pluck('product_id');

            if ($ids->isEmpty() || $ids->count() > self::MAX_PRODUCT_LINKS) {
                return [];
            }

            // The product NAME is the useful label on a notification chip;
            // the SKU is an internal reference.
            return \App\Models\Product::whereIn('id', $ids->all())
                ->get()
                ->map(fn ($product) => self::ref('product', $product->id, null, $product->name))
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{primary: array, links: array<int, array>}
     */
    public static function forInvoice(Invoice $invoice): array
    {
        $primary = self::ref('invoice', $invoice->id, $invoice->invoice_number);

        $links = self::compact([
            $primary,
            $invoice->customer_id ? self::ref('customer', $invoice->customer_id, null, $invoice->customer?->name) : null,
            $invoice->sales_order_id ? self::ref('sales_order', $invoice->sales_order_id, $invoice->salesOrder?->sales_order_number) : null,
            $invoice->quotation_id ? self::ref('quotation', $invoice->quotation_id, $invoice->quotation?->quotation_number) : null,
            ($firstDeliveryOrder = $invoice->deliveryOrders()->first())
                ? self::ref('delivery_order', $firstDeliveryOrder->id, $firstDeliveryOrder->delivery_order_number)
                : null,
        ]);

        return [
            'primary' => $primary,
            'links' => array_merge($links, self::productRefs($invoice->lines())),
        ];
    }

    /**
     * @return array{primary: array, links: array<int, array>}
     */
    public static function forDeliveryOrder(DeliveryOrder $deliveryOrder): array
    {
        $primary = self::ref('delivery_order', $deliveryOrder->id, $deliveryOrder->delivery_order_number);

        $links = self::compact([
            $primary,
            $deliveryOrder->customer_id ? self::ref('customer', $deliveryOrder->customer_id, null, $deliveryOrder->customer?->name) : null,
            $deliveryOrder->sales_order_id ? self::ref('sales_order', $deliveryOrder->sales_order_id, $deliveryOrder->salesOrder?->sales_order_number) : null,
            $deliveryOrder->quotation_id ? self::ref('quotation', $deliveryOrder->quotation_id, $deliveryOrder->quotation?->quotation_number) : null,
            $deliveryOrder->invoice_id ? self::ref('invoice', $deliveryOrder->invoice_id, $deliveryOrder->invoice?->invoice_number) : null,
        ]);

        return [
            'primary' => $primary,
            'links' => array_merge($links, self::productRefs($deliveryOrder->lines())),
        ];
    }

    /**
     * @return array{primary: array, links: array<int, array>}
     */
    public static function forQuotation(Quotation $quotation): array
    {
        $primary = self::ref('quotation', $quotation->id, $quotation->quotation_number);

        $links = self::compact([
            $primary,
            $quotation->customer_id ? self::ref('customer', $quotation->customer_id, null, $quotation->customer?->name) : null,
            $quotation->sales_order_id ? self::ref('sales_order', $quotation->sales_order_id, $quotation->salesOrder?->sales_order_number) : null,
        ]);

        return [
            'primary' => $primary,
            'links' => array_merge($links, self::productRefs($quotation->lines())),
        ];
    }

    /**
     * @return array{primary: array, links: array<int, array>}
     */
    public static function forSalesOrder(SalesOrder $salesOrder): array
    {
        $primary = self::ref('sales_order', $salesOrder->id, $salesOrder->sales_order_number);

        $links = self::compact([
            $primary,
            $salesOrder->customer_id ? self::ref('customer', $salesOrder->customer_id, null, $salesOrder->customer?->name) : null,
        ]);

        return [
            'primary' => $primary,
            'links' => array_merge($links, self::productRefs($salesOrder->lines())),
        ];
    }

    /**
     * A payment links to the invoice it settles (payments have no page of
     * their own, so the invoice is what the user can open).
     *
     * @return array{primary: array, links: array<int, array>}
     */
    public static function forPayment(Payment $payment): array
    {
        $primary = self::ref('payment', $payment->id, $payment->payment_number);

        $links = self::compact([
            $primary,
            $payment->invoice_id ? self::ref('invoice', $payment->invoice_id, $payment->invoice?->invoice_number) : null,
            $payment->customer_id ? self::ref('customer', $payment->customer_id, null, $payment->customer?->name) : null,
        ]);

        return ['primary' => $primary, 'links' => $links];
    }

    /**
     * A credit note links to the invoice it credits.
     *
     * @return array{primary: array, links: array<int, array>}
     */
    public static function forCreditNote(CustomerCreditNote $creditNote): array
    {
        $primary = self::ref('credit_note', $creditNote->id, $creditNote->credit_note_number);

        $links = self::compact([
            $primary,
            $creditNote->invoice_id ? self::ref('invoice', $creditNote->invoice_id, $creditNote->invoice?->invoice_number) : null,
            $creditNote->customer_id ? self::ref('customer', $creditNote->customer_id, null, $creditNote->customer?->name) : null,
        ]);

        return ['primary' => $primary, 'links' => $links];
    }

    /**
     * Route a document model to its reference builder. Approval notifications
     * cover quotations and sales orders as well as invoices / delivery orders
     * (issue #650); an unknown model still gets a typed primary reference.
     *
     * @return array{primary: array|null, links: array<int, array>}
     */
    public static function forDocument(Model $document): array
    {
        return match (true) {
            $document instanceof Invoice => self::forInvoice($document),
            $document instanceof DeliveryOrder => self::forDeliveryOrder($document),
            $document instanceof Quotation => self::forQuotation($document),
            $document instanceof SalesOrder => self::forSalesOrder($document),
            $document instanceof Payment => self::forPayment($document),
            $document instanceof CustomerCreditNote => self::forCreditNote($document),
            default => [
                'primary' => self::ref(strtolower(class_basename($document)), $document->getKey()),
                'links' => [],
            ],
        };
    }
}
