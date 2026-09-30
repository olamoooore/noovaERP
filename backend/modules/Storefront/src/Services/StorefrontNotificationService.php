<?php

namespace Modules\Storefront\Services;

use Illuminate\Support\Facades\Log;
use Modules\Communication\Services\CommunicationEngine;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontReturn;
use Modules\Storefront\Models\StorefrontSettings;

/**
 * Customer lifecycle messaging (FR-MSG-02 pilot scope, FR-MSG-06 merchant
 * toggles) strictly through the Communication engine — non-negotiable #4,
 * zero direct Mail/provider calls (the P-07 anti-pattern).
 *
 * The P-05 fix (subtypes registered at boot) is what makes these actually
 * deliverable: unregistered subtypes previously died inside the queued
 * listener with a silent failed_jobs entry.
 *
 * Failure containment: a messaging problem must never fail a placed/paid
 * order — send failures are logged (and the engine's own outbox retry/backoff
 * handles transient transport issues per FR-MSG-04).
 */
final class StorefrontNotificationService
{
    public function __construct(
        private readonly CommunicationEngine $engine,
    ) {}

    public function orderPlaced(StorefrontOrder $order, ?string $confirmToken = null): void
    {
        if (! $this->enabled('order_placed')) {
            return;
        }

        $body = "Thanks — your order {$order->order_number} is placed. Total: {$order->total} {$order->currency}.";
        if ($confirmToken !== null) {
            $body .= ' Track your order any time: ?sfconf='.$confirmToken;
        }

        $this->send(
            $order,
            'storefront.order.placed',
            "Order {$order->order_number} confirmed",
            $body,
            'storefront.order.placed',
            $this->orderVars($order, $confirmToken)
        );
    }

    public function orderPaid(StorefrontOrder $order): void
    {
        if (! $this->enabled('order_paid')) {
            return;
        }

        $this->send(
            $order,
            'storefront.order.paid',
            "Payment received for order {$order->order_number}",
            "We received your payment for order {$order->order_number} ({$order->total} {$order->currency}). We'll email you when it ships.",
            'storefront.order.paid',
            $this->orderVars($order)
        );
    }

    public function orderShipped(StorefrontOrder $order): void
    {
        if (! $this->enabled('order_shipped')) {
            return;
        }

        $tracking = collect($order->tracking_numbers ?? [])->first();

        $vars = $this->orderVars($order);
        $vars['shipment'] = ['tracking' => (string) $tracking];

        $this->send(
            $order,
            'storefront.order.shipped',
            "Your order {$order->order_number} is on its way",
            "Order {$order->order_number} has been shipped.".($tracking ? " Tracking: {$tracking}." : ''),
            'storefront.order.shipped',
            $vars
        );
    }

    public function orderRefunded(StorefrontOrder $order, float $amount, string $reason): void
    {
        if (! $this->enabled('order_refunded')) {
            return;
        }

        $vars = $this->orderVars($order);
        $vars['refund'] = [
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => (string) $order->currency,
            'reason' => $reason,
        ];

        $this->send(
            $order,
            'storefront.order.refunded',
            "Refund issued for order {$order->order_number}",
            "A refund of {$amount} {$order->currency} was issued for order {$order->order_number}. Reason: {$reason}.",
            'storefront.order.refunded',
            $vars
        );
    }

    /**
     * FR-MSG return lifecycle (P4, A-22 "full message sequence"): one call
     * per transition event of the returns flow. Toggle keys are
     * return_requested/return_approved/return_rejected/return_received/
     * return_refunded, default-on like the order events.
     */
    public function returnEvent(StorefrontReturn $ret, string $event): void
    {
        if (! $this->enabled('return_'.$event)) {
            return;
        }

        $order = StorefrontOrder::query()->find($ret->order_id);
        if ($order === null) {
            return;
        }

        $vars = $this->orderVars($order);
        $vars['return'] = [
            'id' => (string) $ret->id,
            'amount' => number_format((float) $ret->refund_amount, 2, '.', ''),
            'currency' => (string) $order->currency,
            'reason' => (string) $ret->reason,
        ];

        [$subject, $body] = match ($event) {
            'requested' => ["Return requested for order {$order->order_number}", "We received your return request (#{$ret->id}) for order {$order->order_number}. We'll review it shortly."],
            'approved' => ["Return #{$ret->id} approved", "Your return #{$ret->id} for order {$order->order_number} was approved. Please send the items back; we refund on receipt."],
            'rejected' => ["Return #{$ret->id} declined", "We could not approve return #{$ret->id} for order {$order->order_number}. Reply to this email if you'd like help."],
            'received' => ["Return #{$ret->id} received", "We received your returned items for order {$order->order_number}. The refund is being processed."],
            'refunded' => ["Refund issued for return #{$ret->id}", "Your return #{$ret->id} was refunded ({$ret->refund_amount} {$order->currency}) for order {$order->order_number}."],
            default => ["Return #{$ret->id} update", "Return #{$ret->id} for order {$order->order_number}: {$event}."],
        };

        $this->send(
            $order,
            'storefront.return.'.$event,
            $subject,
            $body,
            'storefront.return.'.$event,
            $vars
        );
    }

    /**
     * Merchant toggles live in storefront_settings.notification_settings
     * (FR-MSG-06). Missing keys default to ENABLED — transactional order
     * mail is on until a merchant turns it off (no patching per tenant).
     */
    private function enabled(string $key): bool
    {
        try {
            $settings = StorefrontSettings::current();
            $map = $settings->notification_settings;

            return is_array($map) && array_key_exists($key, $map)
                ? (bool) ($map[$key]['email'] ?? true)
                : true;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function orderVars(StorefrontOrder $order, ?string $confirmToken = null): array
    {
        return [
            'company' => ['name' => $this->companyName()],
            'order' => [
                'order_number' => $order->order_number,
                'total' => (string) $order->total,
                'currency' => (string) $order->currency,
                'status' => $order->status,
                'confirmation_url' => $confirmToken !== null
                    ? \Modules\Storefront\Support\TenantUrl::confirmation($confirmToken)
                    : \Modules\Storefront\Support\TenantUrl::base(),
            ],
        ];
    }

    private function companyName(): string
    {
        try {
            if (function_exists('tenant') && tenant()) {
                return (string) (tenant()->name ?: config('app.name'));
            }
        } catch (\Throwable) {
        }

        return (string) config('app.name');
    }

    private function send(StorefrontOrder $order, string $subtype, string $subject, string $body, ?string $templateKey = null, array $vars = []): void
    {
        if (empty($order->email)) {
            return;
        }

        try {
            $this->engine->sendDirect(
                subject: $subject,
                body: $body,
                recipientEmails: [(string) $order->email],
                channels: ['email'],
                subtype: $subtype,
                templateKey: $templateKey,
                templateVariables: $vars,
                model: 'storefront_order',
                recordId: (int) $order->id,
                tenantId: (function_exists('tenant') && tenant()) ? (string) tenant('id') : null,
                metadata: ['storefront_order_id' => (int) $order->id, 'source' => 'storefront-lifecycle'],
            );
        } catch (\Throwable $e) {
            Log::warning('Storefront lifecycle notification failed (non-fatal)', [
                'subtype' => $subtype,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
