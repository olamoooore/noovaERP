<?php

namespace Modules\PaymentGateway\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Ecommerce\Models\WebOrder;
use Modules\PaymentGateway\Models\PaymentTransaction;
use App\Models\Tenant;
use Stancl\Tenancy\Facades\Tenancy;

class FixStuckWebOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:fix-stuck-web {--order_id= : Specific order ID to fix} {--tenant= : Specific tenant ID}';

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
        
        // Get tenants to process
        $tenants = $tenantId 
            ? Tenant::where('id', $tenantId)->get()
            : Tenant::all();
        
        if ($tenants->isEmpty()) {
            $this->error('No tenants found.');
            return Command::FAILURE;
        }
        
        foreach ($tenants as $tenant) {
            $this->info("Processing tenant: {$tenant->id}");
            
            $tenant->run(function () use ($orderId) {
                $this->processTenantOrders($orderId);
            });
        }

        $this->info('Done!');
        return Command::SUCCESS;
    }
    
    /**
     * Process orders for a single tenant
     */
    protected function processTenantOrders(?int $orderId = null)
    {
        // Check if web_orders table exists
        if (!\Schema::hasTable('web_orders')) {
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
            // Update order status
            $order->update([
                'payment_status' => 'paid',
                'status' => 'confirmed',
                'paid_at' => $order->paid_at ?? now(),
                'confirmed_at' => $order->confirmed_at ?? now(),
            ]);
            
            $this->info("  Updated order status to confirmed/paid");
            
            // Convert to invoice if not already done
            if (!$order->invoice_id) {
                $invoice = $order->convertToInvoice();
                $this->info("  Created invoice: {$invoice->invoice_number}");
                
                // Refresh order to get the invoice_id set by convertToInvoice
                $order->refresh();
            } else {
                $this->info("  Order already has invoice_id: {$order->invoice_id}");
            }
            
            // Update transaction with invoice_id (only if not already set)
            if ($order->invoice_id && !$transaction->invoice_id) {
                $transaction->update([
                    'invoice_id' => $order->invoice_id,
                    'customer_id' => $order->customer_id,
                ]);
                $this->info("  Updated transaction with invoice_id: {$order->invoice_id}");
            }
            
            // Create payment record if not exists
            $existingPayment = \App\Models\Payment::where('reference', $transaction->transaction_id)->first();
            if (!$existingPayment && $order->invoice_id) {
                $invoice = \App\Models\Invoice::find($order->invoice_id);
                $payment = \App\Models\Payment::create([
                    'customer_id' => $order->customer_id,
                    'invoice_id' => $order->invoice_id,
                    'payment_date' => now(),
                    'method' => 'card',
                    'reference' => $transaction->transaction_id,
                    'amount' => $transaction->amount,
                    'notes' => "Online payment via {$transaction->gateway->gateway_name}",
                    'received_by' => $invoice->created_by ?? 1,
                ]);
                $this->info("  Created payment record: {$payment->payment_number}");
            } elseif ($existingPayment) {
                $this->info("  Payment record already exists: {$existingPayment->payment_number}");
            }
            
            // Award loyalty points
            $this->awardLoyaltyPoints($order);
            
            // Update customer stats
            $this->updateCustomerStats($order);
            
            // Post to accounting
            $this->postToAccounting($order, $transaction);
            
            // Mark transaction as processed
            $transaction->update(['processed_at' => $transaction->processed_at ?? now()]);
            
            $this->info("  Order processed successfully!");
            
        } catch (\Exception $e) {
            $this->error("  Failed to process order: {$e->getMessage()}");
            Log::error('Failed to fix stuck web order', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
    
    /**
     * Award loyalty points after successful payment.
     */
    protected function awardLoyaltyPoints(WebOrder $order): void
    {
        if (!$order->customer_id || $order->loyalty_points_earned > 0) return;

        try {
            $earningService = app(\Modules\Loyalty\Services\PointsEarningService::class);
            $result = $earningService->awardPoints(
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
                
                $this->info("  Awarded {$result['points_earned']} loyalty points");
            }
        } catch (\Exception $e) {
            $this->warn("  Failed to award loyalty points: {$e->getMessage()}");
        }
    }
    
    /**
     * Update customer purchase statistics.
     */
    protected function updateCustomerStats(WebOrder $order): void
    {
        if (!$order->customer_id) return;

        try {
            $customer = \App\Models\Customer::find($order->customer_id);
            if ($customer) {
                $customer->increment('total_orders');
                $customer->increment('total_purchases', (float) $order->total);
                $customer->update([
                    'last_interaction_at' => now(),
                    'average_order_value' => $customer->total_orders > 0
                        ? $customer->total_purchases / $customer->total_orders
                        : 0,
                ]);
                
                $this->info("  Updated customer stats");
            }
        } catch (\Exception $e) {
            $this->warn("  Failed to update customer stats: {$e->getMessage()}");
        }
    }
    
    /**
     * Post web order to accounting system.
     */
    protected function postToAccounting(WebOrder $order, PaymentTransaction $transaction): void
    {
        try {
            $postingService = app(\Modules\Ecommerce\Services\WebOrderPostingService::class);
            $paymentMethod = $transaction->payment_method ?? $transaction->gateway->gateway_name ?? 'card';
            
            $entry = $postingService->postPaidOrder(
                $order,
                (float) $transaction->amount,
                $paymentMethod
            );
            
            if ($entry) {
                $this->info("  Posted to accounting: {$entry->entry_number}");
            }
        } catch (\Exception $e) {
            $this->warn("  Failed to post to accounting: {$e->getMessage()}");
        }
    }
}
