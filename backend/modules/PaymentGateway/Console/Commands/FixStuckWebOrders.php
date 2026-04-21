<?php

namespace Modules\PaymentGateway\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Ecommerce\Models\WebOrder;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;
use App\Models\Tenant;

class FixStuckWebOrders extends Command
{
    public function __construct(
        private readonly PaymentTransactionCompletionService $completionService,
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
        
        if (!$tenantId) {
            $this->error('The --tenant flag is required for this command.');
            $this->info('Usage: php artisan orders:fix-stuck-web --tenant=<tenant-id>');
            return Command::FAILURE;
        }

        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
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
        if (!Schema::hasTable('web_orders')) {
            $this->info('  web_orders table does not exist, skipping tenant.');
            return;
        }
        
        // Find web orders that are paid but still pending
        $query = WebOrder::where('payment_status', 'paid')
            ->where('status', 'pending');
            
        if ($orderId) {
            $query->where('id', $orderId);
        }
        
        $stuckOrders = $query->get();
        
        if ($stuckOrders->isEmpty()) {
            // Also check for orders with completed payment transactions but pending status
            $this->info('  No orders with payment_status=paid and status=pending found.');
            $this->info('  Checking for orders with completed payment transactions...');
            
            $completedTransactions = PaymentTransaction::where('status', 'completed')
                ->whereNotNull('metadata->order_id')
                ->get();
            
            foreach ($completedTransactions as $transaction) {
                $orderId = $transaction->metadata['order_id'] ?? null;
                if (!$orderId) continue;
                
                $webOrder = WebOrder::find($orderId);
                if (!$webOrder) continue;
                
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
        if (!$transaction) {
            $transaction = PaymentTransaction::where('transaction_id', $order->payment_transaction_id)
                ->orWhereJsonContains('metadata->order_id', $order->id)
                ->first();
        }
        
        if (!$transaction) {
            $this->warn("  No payment transaction found for order {$order->order_number}");
            return;
        }
        
        // Check if transaction is completed
        if ($transaction->status !== 'completed') {
            $this->warn("  Transaction {$transaction->transaction_id} status is: {$transaction->status}");
            return;
        }
        
        try {
            $this->completionService->complete(
                $transaction,
                $transaction->payment_method ?? $transaction->gateway->gateway_name ?? 'card'
            );

            $order->refresh();

            $this->info("  Canonical completion repaired the order");
            if ($order->invoice_id) {
                $this->info("  Order invoice_id: {$order->invoice_id}");
            }
            
            $this->info("  Order processed successfully!");
            
        } catch (\Exception $e) {
            $this->error("  Failed to process order: {$e->getMessage()}");
            Log::error('Failed to fix stuck web order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
}
