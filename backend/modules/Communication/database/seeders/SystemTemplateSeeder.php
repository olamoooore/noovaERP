<?php

namespace Modules\Communication\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Communication\Models\CommunicationTemplate;

/**
 * SystemTemplateSeeder — Seeds default notification templates for all ERP modules.
 *
 * Run with: php artisan db:seed --class="Modules\Communication\Database\Seeders\SystemTemplateSeeder"
 *
 * Templates use {{variable}} syntax for dynamic content.
 * Tenants can override these by creating tenant-specific templates with the same key.
 */
class SystemTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = $this->getTemplates();

        foreach ($templates as $template) {
            CommunicationTemplate::updateOrCreate(
                [
                    'key' => $template['key'],
                    'tenant_id' => null, // System-wide defaults
                ],
                $template,
            );
        }

        $this->command->info('Seeded ' . count($templates) . ' system communication templates.');
    }

    protected function getTemplates(): array
    {
        return [
            // ══════════════════════════════
            //  SALES / ORDERS
            // ══════════════════════════════
            [
                'key' => 'order.confirmed',
                'module' => 'Sales',
                'event' => 'order.confirmed',
                'name' => 'Order Confirmation',
                'subject' => 'Order #{{order.number}} Confirmed',
                'body_html' => $this->wrap('
                    <h2>Order Confirmed!</h2>
                    <p>Hi {{customer.name}},</p>
                    <p>Your order <strong>#{{order.number}}</strong> has been confirmed.</p>
                    <table style="width:100%; border-collapse:collapse; margin:16px 0;">
                        <tr style="background:#f8f9fa;">
                            <td style="padding:8px; border:1px solid #ddd;"><strong>Order Total</strong></td>
                            <td style="padding:8px; border:1px solid #ddd;">{{order.total}}</td>
                        </tr>
                        <tr>
                            <td style="padding:8px; border:1px solid #ddd;"><strong>Items</strong></td>
                            <td style="padding:8px; border:1px solid #ddd;">{{order.item_count}} items</td>
                        </tr>
                        <tr style="background:#f8f9fa;">
                            <td style="padding:8px; border:1px solid #ddd;"><strong>Date</strong></td>
                            <td style="padding:8px; border:1px solid #ddd;">{{order.date}}</td>
                        </tr>
                    </table>
                    <p>We\'ll notify you when your order ships.</p>
                '),
                'body_plain' => "Order Confirmed!\n\nHi {{customer.name}},\nYour order #{{order.number}} has been confirmed.\nTotal: {{order.total}}\nItems: {{order.item_count}}\nDate: {{order.date}}\n\nWe'll notify you when your order ships.",
                'channels' => ['email', 'in_app'],
                'variables' => ['customer.name', 'order.number', 'order.total', 'order.item_count', 'order.date'],
                'locale' => 'en',
                'is_active' => true,
            ],

            [
                'key' => 'order.shipped',
                'module' => 'Sales',
                'event' => 'order.shipped',
                'name' => 'Order Shipped',
                'subject' => 'Order #{{order.number}} Has Been Shipped',
                'body_html' => $this->wrap('
                    <h2>Your Order is On Its Way!</h2>
                    <p>Hi {{customer.name}},</p>
                    <p>Your order <strong>#{{order.number}}</strong> has been shipped.</p>
                    <p><strong>Tracking Number:</strong> {{shipment.tracking_number}}</p>
                    <p><strong>Carrier:</strong> {{shipment.carrier}}</p>
                '),
                'body_plain' => "Your Order is On Its Way!\n\nHi {{customer.name}},\nOrder #{{order.number}} has been shipped.\nTracking: {{shipment.tracking_number}}\nCarrier: {{shipment.carrier}}",
                'channels' => ['email', 'in_app', 'sms'],
                'variables' => ['customer.name', 'order.number', 'shipment.tracking_number', 'shipment.carrier'],
                'locale' => 'en',
                'is_active' => true,
            ],

            [
                'key' => 'order.admin_alert',
                'module' => 'Sales',
                'event' => 'order.created',
                'name' => 'New Order Alert (Admin)',
                'subject' => 'New Order #{{order.number}} — {{order.total}}',
                'body_html' => $this->wrap('
                    <h2>New Order Received</h2>
                    <p>A new order has been placed:</p>
                    <ul>
                        <li><strong>Order:</strong> #{{order.number}}</li>
                        <li><strong>Customer:</strong> {{customer.name}}</li>
                        <li><strong>Total:</strong> {{order.total}}</li>
                        <li><strong>Source:</strong> {{order.source}}</li>
                    </ul>
                '),
                'body_plain' => "New Order #{{order.number}}\nCustomer: {{customer.name}}\nTotal: {{order.total}}\nSource: {{order.source}}",
                'channels' => ['email', 'in_app'],
                'variables' => ['order.number', 'customer.name', 'order.total', 'order.source'],
                'locale' => 'en',
                'is_active' => true,
            ],

            // ══════════════════════════════
            //  INVOICING / ACCOUNTING
            // ══════════════════════════════
            [
                'key' => 'invoice.sent',
                'module' => 'Accounting',
                'event' => 'invoice.sent',
                'name' => 'Invoice Sent',
                'subject' => 'Invoice {{invoice.number}} from {{company.name}}',
                'body_html' => $this->wrap('
                    <h2>Invoice</h2>
                    <p>Hi {{customer.name}},</p>
                    <p>Please find your invoice details below:</p>
                    <table style="width:100%; border-collapse:collapse; margin:16px 0;">
                        <tr style="background:#f8f9fa;">
                            <td style="padding:8px; border:1px solid #ddd;"><strong>Invoice #</strong></td>
                            <td style="padding:8px; border:1px solid #ddd;">{{invoice.number}}</td>
                        </tr>
                        <tr>
                            <td style="padding:8px; border:1px solid #ddd;"><strong>Amount Due</strong></td>
                            <td style="padding:8px; border:1px solid #ddd;">{{invoice.total}}</td>
                        </tr>
                        <tr style="background:#f8f9fa;">
                            <td style="padding:8px; border:1px solid #ddd;"><strong>Due Date</strong></td>
                            <td style="padding:8px; border:1px solid #ddd;">{{invoice.due_date}}</td>
                        </tr>
                    </table>
                '),
                'body_plain' => "Invoice from {{company.name}}\n\nHi {{customer.name}},\nInvoice #{{invoice.number}}\nAmount: {{invoice.total}}\nDue: {{invoice.due_date}}",
                'channels' => ['email'],
                'variables' => ['customer.name', 'company.name', 'invoice.number', 'invoice.total', 'invoice.due_date'],
                'locale' => 'en',
                'is_active' => true,
            ],

            [
                'key' => 'invoice.overdue',
                'module' => 'Accounting',
                'event' => 'invoice.overdue',
                'name' => 'Invoice Overdue Reminder',
                'subject' => 'Overdue: Invoice {{invoice.number}} — {{invoice.total}}',
                'body_html' => $this->wrap('
                    <h2 style="color:#dc3545;">Overdue Invoice</h2>
                    <p>Hi {{customer.name}},</p>
                    <p>Invoice <strong>#{{invoice.number}}</strong> for <strong>{{invoice.total}}</strong> was due on <strong>{{invoice.due_date}}</strong>.</p>
                    <p>Please arrange payment at your earliest convenience.</p>
                '),
                'body_plain' => "OVERDUE: Invoice #{{invoice.number}} for {{invoice.total}} was due {{invoice.due_date}}. Please arrange payment.",
                'channels' => ['email', 'sms'],
                'variables' => ['customer.name', 'invoice.number', 'invoice.total', 'invoice.due_date'],
                'locale' => 'en',
                'is_active' => true,
            ],

            // ══════════════════════════════
            //  PAYMENTS
            // ══════════════════════════════
            [
                'key' => 'payment.received',
                'module' => 'PaymentGateway',
                'event' => 'payment.received',
                'name' => 'Payment Receipt',
                'subject' => 'Payment Received — {{payment.amount}}',
                'body_html' => $this->wrap('
                    <h2>Payment Received</h2>
                    <p>Hi {{customer.name}},</p>
                    <p>We have received your payment of <strong>{{payment.amount}}</strong>.</p>
                    <table style="width:100%; border-collapse:collapse; margin:16px 0;">
                        <tr style="background:#f8f9fa;">
                            <td style="padding:8px; border:1px solid #ddd;"><strong>Reference</strong></td>
                            <td style="padding:8px; border:1px solid #ddd;">{{payment.reference}}</td>
                        </tr>
                        <tr>
                            <td style="padding:8px; border:1px solid #ddd;"><strong>Method</strong></td>
                            <td style="padding:8px; border:1px solid #ddd;">{{payment.method}}</td>
                        </tr>
                        <tr style="background:#f8f9fa;">
                            <td style="padding:8px; border:1px solid #ddd;"><strong>Date</strong></td>
                            <td style="padding:8px; border:1px solid #ddd;">{{payment.date}}</td>
                        </tr>
                    </table>
                    <p>Thank you for your prompt payment!</p>
                '),
                'body_plain' => "Payment Received\n\nHi {{customer.name}},\nWe received {{payment.amount}}.\nReference: {{payment.reference}}\nMethod: {{payment.method}}\nDate: {{payment.date}}",
                'channels' => ['email', 'in_app'],
                'variables' => ['customer.name', 'payment.amount', 'payment.reference', 'payment.method', 'payment.date'],
                'locale' => 'en',
                'is_active' => true,
            ],

            [
                'key' => 'payment.failed',
                'module' => 'PaymentGateway',
                'event' => 'payment.failed',
                'name' => 'Payment Failed',
                'subject' => 'Payment Failed — Action Required',
                'body_html' => $this->wrap('
                    <h2 style="color:#dc3545;">Payment Failed</h2>
                    <p>Hi {{customer.name}},</p>
                    <p>Your payment of <strong>{{payment.amount}}</strong> could not be processed.</p>
                    <p><strong>Reason:</strong> {{payment.failure_reason}}</p>
                    <p>Please try again or use a different payment method.</p>
                '),
                'body_plain' => "Payment Failed\nAmount: {{payment.amount}}\nReason: {{payment.failure_reason}}\nPlease try again.",
                'channels' => ['email', 'in_app', 'sms'],
                'variables' => ['customer.name', 'payment.amount', 'payment.failure_reason'],
                'locale' => 'en',
                'is_active' => true,
            ],

            // ══════════════════════════════
            //  INVENTORY
            // ══════════════════════════════
            [
                'key' => 'inventory.low_stock',
                'module' => 'Inventory',
                'event' => 'inventory.low_stock',
                'name' => 'Low Stock Alert',
                'subject' => 'Low Stock Alert: {{product.name}}',
                'body_html' => $this->wrap('
                    <h2 style="color:#ffc107;">⚠ Low Stock Alert</h2>
                    <p>Product <strong>{{product.name}}</strong> (SKU: {{product.sku}}) is running low.</p>
                    <table style="width:100%; border-collapse:collapse; margin:16px 0;">
                        <tr style="background:#f8f9fa;">
                            <td style="padding:8px; border:1px solid #ddd;"><strong>Current Stock</strong></td>
                            <td style="padding:8px; border:1px solid #ddd;">{{product.current_stock}}</td>
                        </tr>
                        <tr>
                            <td style="padding:8px; border:1px solid #ddd;"><strong>Reorder Level</strong></td>
                            <td style="padding:8px; border:1px solid #ddd;">{{product.reorder_level}}</td>
                        </tr>
                    </table>
                '),
                'body_plain' => "LOW STOCK: {{product.name}} ({{product.sku}})\nCurrent: {{product.current_stock}}\nReorder Level: {{product.reorder_level}}",
                'channels' => ['email', 'in_app'],
                'variables' => ['product.name', 'product.sku', 'product.current_stock', 'product.reorder_level'],
                'locale' => 'en',
                'is_active' => true,
            ],

            // ══════════════════════════════
            //  POS
            // ══════════════════════════════
            [
                'key' => 'pos.daily_summary',
                'module' => 'POS',
                'event' => 'pos.daily_summary',
                'name' => 'POS Daily Summary',
                'subject' => 'POS Daily Summary — {{summary.date}}',
                'body_html' => $this->wrap('
                    <h2>POS Daily Summary</h2>
                    <p><strong>Date:</strong> {{summary.date}}</p>
                    <table style="width:100%; border-collapse:collapse; margin:16px 0;">
                        <tr style="background:#f8f9fa;">
                            <td style="padding:8px; border:1px solid #ddd;"><strong>Total Sales</strong></td>
                            <td style="padding:8px; border:1px solid #ddd;">{{summary.total_sales}}</td>
                        </tr>
                        <tr>
                            <td style="padding:8px; border:1px solid #ddd;"><strong>Transactions</strong></td>
                            <td style="padding:8px; border:1px solid #ddd;">{{summary.transaction_count}}</td>
                        </tr>
                        <tr style="background:#f8f9fa;">
                            <td style="padding:8px; border:1px solid #ddd;"><strong>Average Order</strong></td>
                            <td style="padding:8px; border:1px solid #ddd;">{{summary.average_order}}</td>
                        </tr>
                    </table>
                '),
                'body_plain' => "POS Daily Summary — {{summary.date}}\nTotal Sales: {{summary.total_sales}}\nTransactions: {{summary.transaction_count}}\nAvg Order: {{summary.average_order}}",
                'channels' => ['email', 'in_app'],
                'variables' => ['summary.date', 'summary.total_sales', 'summary.transaction_count', 'summary.average_order'],
                'locale' => 'en',
                'is_active' => true,
            ],

            // ══════════════════════════════
            //  EVENTS
            // ══════════════════════════════
            [
                'key' => 'event.ticket_confirmed',
                'module' => 'EventManagement',
                'event' => 'event.ticket_confirmed',
                'name' => 'Event Ticket Confirmation',
                'subject' => 'Your Ticket for {{event.name}}',
                'body_html' => $this->wrap('
                    <h2>Ticket Confirmed!</h2>
                    <p>Hi {{customer.name}},</p>
                    <p>Your ticket for <strong>{{event.name}}</strong> has been confirmed.</p>
                    <table style="width:100%; border-collapse:collapse; margin:16px 0;">
                        <tr style="background:#f8f9fa;">
                            <td style="padding:8px; border:1px solid #ddd;"><strong>Event</strong></td>
                            <td style="padding:8px; border:1px solid #ddd;">{{event.name}}</td>
                        </tr>
                        <tr>
                            <td style="padding:8px; border:1px solid #ddd;"><strong>Date</strong></td>
                            <td style="padding:8px; border:1px solid #ddd;">{{event.date}}</td>
                        </tr>
                        <tr style="background:#f8f9fa;">
                            <td style="padding:8px; border:1px solid #ddd;"><strong>Ticket Type</strong></td>
                            <td style="padding:8px; border:1px solid #ddd;">{{ticket.type}}</td>
                        </tr>
                        <tr>
                            <td style="padding:8px; border:1px solid #ddd;"><strong>Ticket #</strong></td>
                            <td style="padding:8px; border:1px solid #ddd;">{{ticket.number}}</td>
                        </tr>
                    </table>
                '),
                'body_plain' => "Ticket Confirmed!\n\nEvent: {{event.name}}\nDate: {{event.date}}\nTicket: {{ticket.type}} — #{{ticket.number}}",
                'channels' => ['email', 'in_app'],
                'variables' => ['customer.name', 'event.name', 'event.date', 'ticket.type', 'ticket.number'],
                'locale' => 'en',
                'is_active' => true,
            ],

            [
                'key' => 'event.reminder',
                'module' => 'EventManagement',
                'event' => 'event.reminder',
                'name' => 'Event Reminder',
                'subject' => 'Reminder: {{event.name}} — {{event.date}}',
                'body_html' => $this->wrap('
                    <h2>Event Reminder</h2>
                    <p>Hi {{customer.name}},</p>
                    <p>This is a reminder that <strong>{{event.name}}</strong> is coming up on <strong>{{event.date}}</strong>.</p>
                    <p><strong>Venue:</strong> {{event.venue}}</p>
                '),
                'body_plain' => "Reminder: {{event.name}} on {{event.date}}\nVenue: {{event.venue}}",
                'channels' => ['email', 'in_app', 'sms', 'push'],
                'variables' => ['customer.name', 'event.name', 'event.date', 'event.venue'],
                'locale' => 'en',
                'is_active' => true,
            ],

            // ══════════════════════════════
            //  FINANCIAL REPORTING
            // ══════════════════════════════
            [
                'key' => 'report.scheduled',
                'module' => 'FinancialReporting',
                'event' => 'report.generated',
                'name' => 'Scheduled Report Ready',
                'subject' => 'Report Ready: {{report.name}}',
                'body_html' => $this->wrap('
                    <h2>Your Report is Ready</h2>
                    <p>The scheduled report <strong>{{report.name}}</strong> has been generated.</p>
                    <p><strong>Period:</strong> {{report.period}}</p>
                    <p><strong>Generated:</strong> {{report.generated_at}}</p>
                    <p>The report is attached to this email.</p>
                '),
                'body_plain' => "Report Ready: {{report.name}}\nPeriod: {{report.period}}\nGenerated: {{report.generated_at}}",
                'channels' => ['email', 'in_app'],
                'variables' => ['report.name', 'report.period', 'report.generated_at'],
                'locale' => 'en',
                'is_active' => true,
            ],

            // ══════════════════════════════
            //  LOYALTY
            // ══════════════════════════════
            [
                'key' => 'loyalty.points_earned',
                'module' => 'Loyalty',
                'event' => 'loyalty.points_earned',
                'name' => 'Loyalty Points Earned',
                'subject' => 'You earned {{loyalty.points_earned}} points!',
                'body_html' => $this->wrap('
                    <h2>Points Earned!</h2>
                    <p>Hi {{customer.name}},</p>
                    <p>You just earned <strong>{{loyalty.points_earned}} points</strong> on your recent purchase.</p>
                    <p><strong>Total Balance:</strong> {{loyalty.total_points}} points</p>
                '),
                'body_plain' => "You earned {{loyalty.points_earned}} points!\nTotal Balance: {{loyalty.total_points}} points",
                'channels' => ['in_app', 'push'],
                'variables' => ['customer.name', 'loyalty.points_earned', 'loyalty.total_points'],
                'locale' => 'en',
                'is_active' => true,
            ],

            [
                'key' => 'loyalty.reward_available',
                'module' => 'Loyalty',
                'event' => 'loyalty.reward_available',
                'name' => 'Loyalty Reward Available',
                'subject' => 'You\'ve unlocked a reward!',
                'body_html' => $this->wrap('
                    <h2>Reward Unlocked!</h2>
                    <p>Hi {{customer.name}},</p>
                    <p>You\'ve earned enough points to claim: <strong>{{reward.name}}</strong>!</p>
                    <p><strong>Points Required:</strong> {{reward.points_required}}</p>
                    <p><strong>Your Balance:</strong> {{loyalty.total_points}}</p>
                '),
                'body_plain' => "Reward Unlocked: {{reward.name}}!\nPoints Required: {{reward.points_required}}\nYour Balance: {{loyalty.total_points}}",
                'channels' => ['email', 'in_app', 'push'],
                'variables' => ['customer.name', 'reward.name', 'reward.points_required', 'loyalty.total_points'],
                'locale' => 'en',
                'is_active' => true,
            ],

            // ══════════════════════════════
            //  SYSTEM / AUTH
            // ══════════════════════════════
            [
                'key' => 'auth.welcome',
                'module' => 'Core',
                'event' => 'user.registered',
                'name' => 'Welcome Email',
                'subject' => 'Welcome to {{company.name}}!',
                'body_html' => $this->wrap('
                    <h2>Welcome!</h2>
                    <p>Hi {{user.name}},</p>
                    <p>Welcome to <strong>{{company.name}}</strong>. Your account has been created.</p>
                    <p>You can get started by logging in to your dashboard.</p>
                '),
                'body_plain' => "Welcome to {{company.name}}!\n\nHi {{user.name}},\nYour account has been created. Log in to get started.",
                'channels' => ['email'],
                'variables' => ['user.name', 'company.name'],
                'locale' => 'en',
                'is_active' => true,
            ],

            [
                'key' => 'auth.password_reset',
                'module' => 'Core',
                'event' => 'user.password_reset',
                'name' => 'Password Reset',
                'subject' => 'Password Reset Request',
                'body_html' => $this->wrap('
                    <h2>Password Reset</h2>
                    <p>Hi {{user.name}},</p>
                    <p>We received a request to reset your password. Use the link below:</p>
                    <p><a href="{{reset.url}}" style="display:inline-block; padding:12px 24px; background:#007bff; color:#fff; text-decoration:none; border-radius:4px;">Reset Password</a></p>
                    <p><small>This link expires in {{reset.expires_in}} minutes.</small></p>
                    <p>If you didn\'t request this, ignore this email.</p>
                '),
                'body_plain' => "Password Reset\n\nHi {{user.name}},\nReset link: {{reset.url}}\nExpires in {{reset.expires_in}} minutes.\nIf you didn't request this, ignore this email.",
                'channels' => ['email'],
                'variables' => ['user.name', 'reset.url', 'reset.expires_in'],
                'locale' => 'en',
                'is_active' => true,
            ],
        ];
    }

    /**
     * Wrap body content in a standard email layout.
     */
    protected function wrap(string $content): string
    {
        return '
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="margin:0; padding:0; background:#f4f4f4; font-family:Arial, Helvetica, sans-serif;">
<div style="max-width:600px; margin:20px auto; background:#ffffff; border-radius:8px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.1);">
    <div style="background:#007bff; padding:20px; text-align:center;">
        <h1 style="color:#ffffff; margin:0; font-size:22px;">{{company.name}}</h1>
    </div>
    <div style="padding:24px 32px;">
        ' . trim($content) . '
    </div>
    <div style="background:#f8f9fa; padding:16px 32px; text-align:center; font-size:12px; color:#999;">
        <p style="margin:0;">© {{company.name}} | Powered by NoovaERP</p>
    </div>
</div>
</body>
</html>';
    }
}
