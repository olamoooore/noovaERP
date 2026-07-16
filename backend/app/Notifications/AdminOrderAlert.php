<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminOrderAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public $orderId;

    public $order; // Runtime cache (not serialized)

    public $type; // 'Web' or 'POS'

    public $tenantSchema; // Tenant schema for queue context

    public function __construct($order, $type = 'Web')
    {
        $this->orderId = $order->id;
        $this->type = $type;

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
            Log::info('AdminOrderAlert: Restored tenant search_path', ['search_path' => $fullPath]);
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
                Log::info('AdminOrderAlert: Loaded tenant mail config', [
                    'host' => $mail['host'] ?? 'not set',
                    'port' => $mail['port'] ?? 'not set',
                    'from' => $mail['from_address'] ?? 'not set',
                ]);
            } else {
                Log::warning('AdminOrderAlert: No tenant mail config found, using defaults', [
                    'host' => config('mail.mailers.smtp.host'),
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('AdminOrderAlert: Failed to load tenant mail config', ['error' => $e->getMessage()]);
        }
    }

    protected function getOrder()
    {
        if ($this->order && ($this->order instanceof \Modules\Ecommerce\Models\WebOrder || $this->order instanceof \Modules\POS\Models\PosOrder)) {
            return $this->order;
        }

        // Restore tenant context before querying
        $this->ensureTenantContext();

        try {
            if ($this->type === 'POS') {
                $this->order = \Modules\POS\Models\PosOrder::findOrFail($this->orderId);
            } else {
                $this->order = \Modules\Ecommerce\Models\WebOrder::findOrFail($this->orderId);
            }
        } catch (\Exception $e) {
            Log::error("AdminOrderAlert: Failed to find order {$this->orderId} type {$this->type}: ".$e->getMessage());
            throw $e;
        }

        return $this->order;
    }

    public function via($notifiable)
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable)
    {
        $order = $this->getOrder();

        return (new MailMessage)
            ->subject("New {$this->type} Order: #{$order->order_number}")
            ->greeting('Hello Admin,')
            ->line("A new {$this->type} order has been placed.")
            ->line("Order Number: {$order->order_number}")
            ->line('Amount: '.number_format($order->total, 2))
            ->line('Please check the dashboard for more details.');
    }

    public function toArray($notifiable)
    {
        $order = $this->getOrder();

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'amount' => $order->total,
            'type' => $this->type,
            'message' => "New {$this->type} Order #{$order->order_number}",
        ];
    }
}
