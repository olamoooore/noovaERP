<?php

namespace Modules\Storefront\Services;

use Illuminate\Support\Facades\Log;
use Modules\Communication\Services\CommunicationEngine;
use Modules\Storefront\Models\StorefrontOrder;
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

        $this->send($order, 'storefront.order.placed', "Order {$order->order_number} confirmed", $body);
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
            "We received your payment for order {$order->order_number} ({$order->total} {$order->currency}). We'll email you when it ships."
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

    private function send(StorefrontOrder $order, string $subtype, string $subject, string $body): void
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
