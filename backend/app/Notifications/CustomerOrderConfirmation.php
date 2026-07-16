<?php

namespace App\Notifications;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

class CustomerOrderConfirmation extends Notification implements ShouldQueue
{
    use Queueable;

    public $orderId;

    public $order; // Runtime cache

    public $type; // 'Web' or 'POS'

    public $invoiceId;

    public $invoice; // Runtime cache

    public $tenantSchema; // Tenant schema for queue context

    public function __construct($order, $type = 'Web')
    {
        $this->orderId = $order->id;
        $this->type = $type;
        $this->invoiceId = $order->invoice_id ?? ($order->invoice->id ?? null);

        // Capture the current tenant schema from the ACTUAL DB connection
        // (config may still say 'public' if search_path was set via raw SQL)
        try {
            $result = DB::select('SHOW search_path');
            $this->tenantSchema = $result[0]->search_path ?? 'public';
        } catch (\Exception $e) {
            $this->tenantSchema = config('database.connections.'.DB::getDefaultConnection().'.search_path') ?? 'public';
        }
    }

    /**
     * Ensure the tenant search_path is set before any DB queries in the queue worker.
     */
    protected function ensureTenantContext(): void
    {
        if ($this->tenantSchema && $this->tenantSchema !== 'public' && $this->tenantSchema !== '"$user", public') {
            $connectionName = DB::getDefaultConnection();

            // Extract the tenant schema name (e.g., "e255e52c-..." from '"e255e52c-...", public')
            $schemaName = trim(explode(',', $this->tenantSchema)[0]);
            // Ensure it's quoted for SET statement
            if (strpos($schemaName, '"') === false) {
                $schemaName = '"'.$schemaName.'"';
            }
            $fullPath = $schemaName.', public';

            config(["database.connections.{$connectionName}.search_path" => $fullPath]);
            DB::purge($connectionName);
            DB::reconnect($connectionName);
            DB::statement("SET search_path TO {$fullPath}");
            Log::info('CustomerOrderConfirmation: Restored tenant search_path', ['search_path' => $fullPath]);
        }

        // Always reload tenant mail config (even if search_path was already correct)
        // because the queue worker starts with default .env mail settings (mailhog)
        $this->loadTenantMailConfig();
    }

    /**
     * Load tenant mail configuration from the settings table.
     */
    protected function loadTenantMailConfig(): void
    {
        try {
            $mail = \App\Models\Setting::getValue('mail_config');
            if ($mail && is_array($mail)) {
                config(['mail.mailers.smtp' => [
                    'transport' => $mail['driver'] ?? 'smtp',
                    'host' => $mail['host'] ?? config('mail.mailers.smtp.host'),
                    'port' => $mail['port'] ?? config('mail.mailers.smtp.port'),
                    'encryption' => $mail['encryption'] ?? config('mail.mailers.smtp.encryption'),
                    'username' => $mail['username'] ?? config('mail.mailers.smtp.username'),
                    'password' => $mail['password'] ?? config('mail.mailers.smtp.password'),
                    'timeout' => null,
                    'local_domain' => env('MAIL_EHLO_DOMAIN'),
                    'stream' => [
                        'ssl' => [
                            'allow_self_signed' => true,
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                        ],
                    ],
                ]]);
                if (! empty($mail['from_address'])) {
                    config(['mail.from.address' => $mail['from_address']]);
                }
                if (! empty($mail['from_name'])) {
                    config(['mail.from.name' => $mail['from_name']]);
                }
                // Purge the mailer so it picks up the new config, then disable SSL peer verification
                app('mail.manager')->purge('smtp');
                $transport = app('mail.manager')->mailer('smtp')->getSymfonyTransport();
                if ($transport instanceof \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport) {
                    $stream = $transport->getStream();
                    if ($stream instanceof \Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream) {
                        $stream->setStreamOptions([
                            'ssl' => [
                                'verify_peer' => false,
                                'verify_peer_name' => false,
                                'allow_self_signed' => true,
                            ],
                        ]);
                    }
                }
                Log::info('CustomerOrderConfirmation: Loaded tenant mail config', [
                    'host' => $mail['host'] ?? 'not set',
                    'port' => $mail['port'] ?? 'not set',
                    'from' => $mail['from_address'] ?? 'not set',
                ]);
            } else {
                Log::warning('CustomerOrderConfirmation: No tenant mail config found, using defaults', [
                    'host' => config('mail.mailers.smtp.host'),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('CustomerOrderConfirmation: Failed to load tenant mail config', ['error' => $e->getMessage()]);
        }
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        Log::info('CustomerOrderConfirmation::toMail ENTERED', [
            'orderId' => $this->orderId,
            'tenantSchema' => $this->tenantSchema,
            'notifiable_class' => get_class($notifiable),
        ]);

        try {
            // Restore tenant context before querying
            $this->ensureTenantContext();

            // Re-hydrate order
            if (! $this->order) {
                $this->order = \Modules\Ecommerce\Models\WebOrder::findOrFail($this->orderId);
                $this->order->load('items');
            }

            Log::info('CustomerOrderConfirmation: Order loaded', [
                'order_number' => $this->order->order_number ?? 'N/A',
                'email' => $this->order->email ?? 'N/A',
            ]);

            // Re-hydrate invoice if needed
            if ($this->invoiceId && ! $this->invoice) {
                $this->invoice = \App\Models\Invoice::find($this->invoiceId);
            }

            // Get customer name
            $customerName = (string) ($this->order->first_name.' '.$this->order->last_name); // Cast to string to be safe
            if (empty(trim($customerName))) {
                $customerName = $notifiable->name ?? 'Valued Customer';
            }

            // Get customer email
            $customerEmail = $this->order->email ?? $notifiable->email;

            // Get currency
            $currency = $this->order->currency ?? config('app.currency', '$');
            if ($currency === 'NGN' || $currency === 'NGN ') {
                $currency = '#';
            } elseif (strlen($currency) > 2) {
                $currency = '$';
            }

            // Format shipping address
            $shippingAddress = null;
            if ($this->order->shipping_address) {
                $addr = $this->order->shipping_address;
                $shippingAddress = [
                    'name' => trim(($addr['first_name'] ?? $this->order->first_name).' '.($addr['last_name'] ?? $this->order->last_name)),
                    'address' => $addr['address'] ?? $addr['street'] ?? '',
                    'address2' => $addr['address2'] ?? $addr['street2'] ?? '',
                    'city' => $addr['city'] ?? '',
                    'state' => $addr['state'] ?? '',
                    'zip' => $addr['zip'] ?? $addr['postal_code'] ?? '',
                    'country' => $addr['country'] ?? '',
                    'phone' => $addr['phone'] ?? $this->order->phone ?? '',
                ];
            }

            Log::info('CustomerOrderConfirmation: Building MailMessage', [
                'to' => $customerEmail,
                'smtp_host' => config('mail.mailers.smtp.host'),
                'smtp_port' => config('mail.mailers.smtp.port'),
            ]);

            // Build the email
            $mail = (new MailMessage)
                ->subject("Order Confirmation: #{$this->order->order_number}")
                ->view('emails.orders.confirmation', [
                    'order' => $this->order,
                    'items' => $this->order->items,
                    'customerName' => $customerName,
                    'customerEmail' => $customerEmail,
                    'currency' => $currency,
                    'shippingAddress' => $shippingAddress,
                ]);

            // Attach invoice PDF if available
            if ($this->invoice) {
                try {
                    $pdf = $this->generateInvoicePdf($currency);
                    $mail->attachData(
                        $pdf,
                        "invoice-{$this->invoice->invoice_number}.pdf",
                        ['mime' => 'application/pdf']
                    );
                } catch (\Exception $e) {
                    Log::error('Failed to generate invoice PDF for order confirmation', [
                        'order_id' => $this->order->id,
                        'invoice_id' => $this->invoice->id ?? 'N/A',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('CustomerOrderConfirmation: Returning MailMessage successfully');

            return $mail;
        } catch (\Exception $e) {
            Log::error('CustomerOrderConfirmation::toMail EXCEPTION', [
                'orderId' => $this->orderId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Generate invoice PDF
     */
    protected function generateInvoicePdf($currency = '$')
    {
        $invoice = $this->invoice;

        // Load relationships
        if (! $invoice->relationLoaded('lines')) {
            $invoice->load('lines.product', 'customer');
        }

        $customer = $invoice->customer;

        // If no customer, create a dummy object from order data
        if (! $customer) {
            $customer = new \stdClass;
            $customer->name = $this->order->first_name.' '.$this->order->last_name;
            $customer->first_name = $this->order->first_name;
            $customer->last_name = $this->order->last_name;
            $customer->email = $this->order->email;
            $customer->phone = $this->order->phone;
            $customer->billing_address = null;
        }

        $html = View::make('emails.orders.invoice', [
            'invoice' => $invoice,
            'customer' => $customer,
            'currency' => $currency,
        ])->render();

        return Pdf::loadHTML($html)->output();
    }

    /**
     * Future placeholder for SMS
     */
    public function toSms($notifiable)
    {
        return "Your NoovaERP order #{$this->order->order_number} is confirmed. Total: {$this->order->total}";
    }
}
