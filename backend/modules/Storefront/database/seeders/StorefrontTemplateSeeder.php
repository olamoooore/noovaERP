<?php

namespace Modules\Storefront\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Communication\Models\CommunicationTemplate;

/**
 * FR-MSG-03: default tenant-branded templates shipped by the Storefront
 * module (system rows: tenant_id NULL, module=storefront). Merchants
 * override any of them by creating tenant-scoped rows with the same key —
 * per-tenant rows win in TemplateRenderer::findTemplate(). Re-running is
 * idempotent (updateOrCreate) and stamps version 1; editing copy later bumps
 * template_version. All placeholders MUST be filled by the sending services
 * (tests assert no raw '{{' survives a rendered send).
 */
class StorefrontTemplateSeeder extends Seeder
{
    public const VERSION = 1;

    /**
     * @return array<int,string> seeded template keys
     */
    public function seeds(): array
    {
        $wrap = function (string $inner): string {
            return '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#1f2937">'
                .'<p style="font-size:12px;color:#6b7280;margin:0 0 16px">{{company.name}}</p>'
                .$inner
                .'<p style="font-size:12px;color:#6b7280;margin-top:24px">This message was sent by {{company.name}} from your store account.</p>'
                .'</div>';
        };

        $templates = [
            [
                'key' => 'storefront.order.placed',
                'name' => 'Storefront — Order placed',
                'event' => 'storefront.order.placed',
                'subject' => 'Your order {{order.order_number}} is confirmed',
                'body' => '<h2 style="font-size:18px">Thank you for your order!</h2>'
                    .'<p>Order <strong>{{order.order_number}}</strong> has been placed.</p>'
                    .'<p>Total: <strong>{{order.total}} {{order.currency}}</strong><br>Status: {{order.status}}</p>'
                    .'<p><a href="{{order.confirmation_url}}" style="color:#4c1d95">Track your order here</a> — the link stays valid after refresh.</p>'
                    .'<p>We will email you again once your payment is confirmed.</p>',
                'variables' => ['company.name', 'order.order_number', 'order.total', 'order.currency', 'order.status', 'order.confirmation_url'],
            ],
            [
                'key' => 'storefront.order.paid',
                'name' => 'Storefront — Order paid',
                'event' => 'storefront.order.paid',
                'subject' => 'Payment received for order {{order.order_number}}',
                'body' => '<h2 style="font-size:18px">Payment received ✔</h2>'
                    .'<p>We received your payment of <strong>{{order.total}} {{order.currency}}</strong> for order <strong>{{order.order_number}}</strong>.</p>'
                    .'<p><a href="{{order.confirmation_url}}" style="color:#4c1d95">View your order</a></p>'
                    .'<p>We will notify you when it ships.</p>',
                'variables' => ['company.name', 'order.order_number', 'order.total', 'order.currency', 'order.confirmation_url'],
            ],
            [
                'key' => 'storefront.order.shipped',
                'name' => 'Storefront — Order shipped',
                'event' => 'storefront.order.shipped',
                'subject' => 'Your order {{order.order_number}} is on its way',
                'body' => '<h2 style="font-size:18px">Shipped!</h2>'
                    .'<p>Order <strong>{{order.order_number}}</strong> has been dispatched.</p>'
                    .'<p>Tracking: <strong>{{shipment.tracking}}</strong></p>'
                    .'<p><a href="{{order.confirmation_url}}" style="color:#4c1d95">Track your order here</a></p>'
                    .'<p>Thank you for shopping with {{company.name}}!</p>',
                'variables' => ['company.name', 'order.order_number', 'order.confirmation_url', 'shipment.tracking'],
            ],
            [
                'key' => 'storefront.order.refunded',
                'name' => 'Storefront — Order refunded',
                'event' => 'storefront.order.refunded',
                'subject' => 'Refund issued for order {{order.order_number}}',
                'body' => '<h2 style="font-size:18px">Refund issued</h2>'
                    .'<p>A refund of <strong>{{refund.amount}} {{order.currency}}</strong> was issued for order <strong>{{order.order_number}}</strong>.</p>'
                    .'<p>Reason: {{refund.reason}}</p>'
                    .'<p><a href="{{order.confirmation_url}}" style="color:#4c1d95">View your order</a></p>'
                    .'<p>Bank processing can take a few days depending on your original payment method.</p>',
                'variables' => ['company.name', 'order.order_number', 'order.currency', 'order.confirmation_url', 'refund.amount', 'refund.reason'],
            ],
            ...$this->returnTemplates($wrap),
            [
                'key' => 'storefront.customer.email_verification',
                'name' => 'Storefront — Email verification',
                'event' => 'storefront.customer.email_verification',
                'subject' => 'Confirm your email for {{company.name}}',
                'body' => '<h2 style="font-size:18px">One step left</h2>'
                    .'<p>Hi {{customer.name}}, confirm your email to activate your {{company.name}} account.</p>'
                    .'<p><a href="{{verify.url}}" style="color:#4c1d95">Confirm my email</a></p>'
                    .'<p style="font-size:12px;color:#6b7280">Or enter code <strong>{{verify.token}}</strong> in the verification form on our site. The link expires in 24 hours.</p>',
                'variables' => ['company.name', 'customer.name', 'verify.url', 'verify.token'],
            ],
            [
                'key' => 'storefront.customer.password_reset',
                'name' => 'Storefront — Password reset',
                'event' => 'storefront.customer.password_reset',
                'subject' => 'Reset your {{company.name}} password',
                'body' => '<h2 style="font-size:18px">Password reset</h2>'
                    .'<p>Hi {{customer.name}}, use the link below to choose a new password.</p>'
                    .'<p><a href="{{reset.url}}" style="color:#4c1d95">Reset my password</a></p>'
                    .'<p style="font-size:12px;color:#6b7280">The link expires in {{reset.expires_in}} minutes and can be used once. If you did not request this, ignore this email.</p>',
                'variables' => ['company.name', 'customer.name', 'reset.url', 'reset.expires_in'],
            ],
            [
                'key' => 'storefront.customer.registered',
                'name' => 'Storefront — Welcome',
                'event' => 'storefront.customer.registered',
                'subject' => 'Welcome to {{company.name}}',
                'body' => '<h2 style="font-size:18px">You\'re in ✔</h2>'
                    .'<p>Welcome {{customer.name}} — your {{company.name}} account is active.</p>'
                    .'<p>Sign in any time to see your orders, addresses and preferences.</p>',
                'variables' => ['company.name', 'customer.name'],
            ],
        ];

        $keys = [];
        foreach ($templates as $t) {
            $row = CommunicationTemplate::withoutGlobalScopes()->updateOrCreate(
                ['key' => $t['key'], 'language' => 'en', 'tenant_id' => null],
                [
                    'name' => $t['name'],
                    'module' => 'storefront',
                    'event' => $t['event'],
                    'subject' => $t['subject'],
                    'body_html' => $wrap($t['body']),
                    'body_plain' => strip_tags(str_replace(
                        ['</p>', '<br>', '</h2>'],
                        ["\n", "\n", "\n"],
                        $t['body']
                    )),
                    'channels' => ['email'],
                    'variables' => $t['variables'],
                    'is_active' => true,
                    'is_system' => true,
                    'template_version' => self::VERSION,
                ]
            );
            $keys[] = $row->key;
        }

        return $keys;
    }

    public function run(): void
    {
        $this->seeds();
    }

    /**
     * Return lifecycle mail set (FR-MSG return events, A-22 full sequence).
     *
     * @return array<int,array<string,mixed>>
     */
    private function returnTemplates(callable $wrap): array
    {
        $rows = [];
        $defs = [
            ['requested', 'Return requested for order {{order.order_number}}',
                '<p>We received your return request <strong>#{{return.id}}</strong> for order <strong>{{order.order_number}}</strong>. We will review it and email you the next steps.</p>',
                ['company.name', 'order.order_number', 'return.id']],
            ['approved', 'Return #{{return.id}} approved',
                '<p>Your return <strong>#{{return.id}}</strong> for order {{order.order_number}} is approved. Please send the items back — the refund (<strong>{{return.amount}} {{order.currency}}</strong>) is issued once we receive them.</p>',
                ['company.name', 'order.order_number', 'return.id', 'return.amount', 'order.currency']],
            ['rejected', 'Return #{{return.id}} declined',
                '<p>We could not approve return <strong>#{{return.id}}</strong> for order {{order.order_number}}. Reason on file: {{return.reason}}. Reply to this email if you would like help.</p>',
                ['company.name', 'order.order_number', 'return.id', 'return.reason']],
            ['received', 'Return #{{return.id}} received',
                '<p>We received your returned items for order <strong>{{order.order_number}}</strong>. The refund is now being processed.</p>',
                ['company.name', 'order.order_number', 'return.id']],
            ['refunded', 'Refund issued for return #{{return.id}}',
                '<p>Your return <strong>#{{return.id}}</strong> was refunded: <strong>{{return.amount}} {{order.currency}}</strong> for order {{order.order_number}}. Bank processing can take a few days.</p>',
                ['company.name', 'order.order_number', 'return.id', 'return.amount', 'order.currency']],
        ];
        foreach ($defs as [$event, $subject, $inner, $vars]) {
            $rows[] = [
                'key' => 'storefront.return.'.$event,
                'name' => 'Storefront — Return '.$event,
                'event' => 'storefront.return.'.$event,
                'subject' => $subject,
                'body' => '<h2 style="font-size:18px">Returns — '.ucfirst($event).'</h2>'.$inner,
                'variables' => $vars,
            ];
        }

        return $rows;
    }
}
