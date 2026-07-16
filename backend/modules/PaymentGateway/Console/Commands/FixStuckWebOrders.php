<?php

namespace Modules\PaymentGateway\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Ecommerce\Models\WebOrder;
use Modules\Ecommerce\Services\WebOrderNotificationService;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentService;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;

class FixStuckWebOrders extends Command
{
    public function __construct(
        private readonly PaymentTransactionCompletionService $completionService,
        private readonly PaymentService $paymentService,
        private readonly WebOrderNotificationService $webOrderNotificationService,
    ) {
        parent::__construct();
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:fix-stuck-web {--order_id= : Specific order ID to fix} {--tenant= : Specific tenant ID (required)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix web orders that are paid but stuck in pending status';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Checking for stuck web orders...');

        $orderId = $this->option('order_id');
        $tenantId = $this->option('tenant');

        if (! $tenantId) {
            $this->error('The --tenant flag is required for this command.');
            $this->info('Usage: php artisan orders:fix-stuck-web --tenant=<tenant-id>');

            return Command::FAILURE;
        }

        $tenant = Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("Tenant not found: {$tenantId}");

            return Command::FAILURE;
        }

        $this->info("Processing tenant: {$tenant->id}");

        $tenant->run(function () use ($orderId) {
            $this->processTenantOrders($orderId);
        });

        $this->info('Done!');

        return Command::SUCCESS;
    }

    /**
     * Process orders for a single tenant
     */
    protected function processTenantOrders(?int $orderId = null)
    {
        // Check if web_orders table exists
        if (! Schema::hasTable('web_orders')) {
            $this->info('  web_orders table does not exist, skipping tenant.');

            return;
        }

        // Find web orders still pending but with a transaction reference (candidate for missed completion)
        $query = WebOrder::query()
            ->where('status', 'pending')
            ->whereNotNull('payment_transaction_id');

        if ($orderId) {
            $query->where('id', $orderId);
        }

        $stuckOrders = $query->get();

        if ($stuckOrders->isEmpty()) {
            // Also check for orders with completed payment transactions but pending status
            $this->info('  No pending orders with transaction reference found.');
            $this->info('  Checking for orders with completed payment transactions...');

            $completedTransactions = PaymentTransaction::where('status', 'completed')
                ->whereNotNull('metadata->order_id')
                ->get();

            foreach ($completedTransactions as $transaction) {
                $orderId = $transaction->metadata['order_id'] ?? null;
                if (! $orderId) {
                    continue;
                }

                $webOrder = WebOrder::find($orderId);
                if (! $webOrder) {
                    continue;
                }

                if ($webOrder->payment_status !== 'paid' || $webOrder->status === 'pending') {
                    $this->processWebOrder($webOrder, $transaction);
                }
            }

            $this->info('  Done checking completed transactions.');

            return;
        }

        $this->info("  Found {$stuckOrders->count()} stuck order(s).");

        foreach ($stuckOrders as $order) {
            $this->processWebOrder($order);
        }
    }

    /**
     * Process a stuck web order
     */
    protected function processWebOrder(WebOrder $order, ?PaymentTransaction $transaction = null)
    {
        $this->info("Processing order: {$order->order_number}");

        // Refresh order to get latest state
        $order->refresh();

        // Find the payment transaction if not provided
        if (! $transaction) {
            $transaction = PaymentTransaction::where('transaction_id', $order->payment_transaction_id)
                ->orWhereJsonContains('metadata->order_id', $order->id)
                ->first();
        }

        if (! $transaction) {
            $this->warn("  No payment transaction found for order {$order->order_number}");

            return;
        }

        // Re-verify gateway status for non-completed transactions.
        if ($transaction->status !== 'completed') {
            try {
                $gatewayStatus = $this->paymentService->confirmPayment($transaction->transaction_id);
                if (($gatewayStatus['status'] ?? null) !== 'completed') {
                    $this->warn("  Transaction {$transaction->transaction_id} status is: {$gatewayStatus['status']}");

                    return;
                }

                $transaction->refresh();
            } catch (\Throwable $e) {
                $this->warn("  Could not verify gateway status for {$transaction->transaction_id}: {$e->getMessage()}");

                return;
            }
        }

        try {
            $wasOperationallyIncomplete = $order->payment_status !== WebOrder::PAYMENT_STATUS_PAID
                || $order->status === WebOrder::STATUS_PENDING
                || ! $order->invoice_id;

            $this->completionService->complete(
                $transaction,
                $transaction->payment_method ?? $transaction->gateway->gateway_name ?? 'card'
            );

            $order->refresh();

            if ($wasOperationallyIncomplete) {
                $this->webOrderNotificationService->dispatchPaidOrderNotifications($order);
            }

            $this->info('  Canonical completion repaired the order');
            if ($order->invoice_id) {
                $this->info("  Order invoice_id: {$order->invoice_id}");
            }

            $this->info('  Order processed successfully!');

        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Journal entry is unbalanced')) {
                $this->warn('  Canonical completion blocked by unbalanced GL posting. Applying operational payment recovery.');

                $this->recoverOperationalPaymentState($order, $transaction);

                return;
            }

            $this->error("  Failed to process order: {$e->getMessage()}");
            Log::error('Failed to fix stuck web order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function recoverOperationalPaymentState(WebOrder $order, PaymentTransaction $transaction): void
    {
        DB::transaction(function () use ($order, $transaction) {
            $order->refresh();

            if ($order->status === WebOrder::STATUS_PENDING) {
                $order->confirm();
            }

            if ($order->payment_status === WebOrder::PAYMENT_STATUS_PENDING) {
                $order->markPaid();
            }

            if (! $order->invoice_id) {
                $invoice = $order->convertToInvoice();
                if ($invoice) {
                    $order->refresh();
                }
            }

            $transaction->update([
                'status' => 'completed',
                'processed_at' => $transaction->processed_at ?? now(),
                'error_message' => 'Operationally completed by recovery command; accounting posting requires follow-up.',
            ]);
        });

        $order->refresh();
        $this->webOrderNotificationService->dispatchPaidOrderNotifications($order);

        $this->info('  Operational payment recovery completed.');
        $this->info("  Order status: {$order->status}, payment_status: {$order->payment_status}");
        if ($order->invoice_id) {
            $this->info("  Order invoice_id: {$order->invoice_id}");
        }
    }
}
