<?php

namespace Modules\Ecommerce\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Ecommerce\Models\WebOrder;
use Modules\PaymentGateway\Models\PaymentTransaction;
use App\Models\Tenant;
use App\Models\StockMovement;

class ProcessAllWebOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:process-all-web {--tenant= : Specific tenant ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process all web orders - create invoices, journal entries, and deduct stock for paid orders';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Processing all web orders...');

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
            
            $tenant->run(function () {
                $this->processTenantOrders();
            });
        }

        $this->info('Done!');
        return Command::SUCCESS;
    }
    
    /**
     * Process orders for a single tenant
     */
    protected function processTenantOrders()
    {
        // Check if web_orders table exists
        if (!\Schema::hasTable('web_orders')) {
            $this->info('  web_orders table does not exist, skipping tenant.');
            return;
        }
        
        // Get ALL web orders
        $orders = WebOrder::orderBy('created_at', 'asc')->get();
        
        if ($orders->isEmpty()) {
            $this->info('  No web orders found.');
            return;
        }
        
        $this->info("  Found {$orders->count()} web order(s).");
        
        $stats = [
            'total' => $orders->count(),
            'already_processed' => 0,
            'processed' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];
        
        foreach ($orders as $order) {
            $result = $this->processOrder($order);
            
            if ($result === 'already_processed') {
                $stats['already_processed']++;
            } elseif ($result === 'processed') {
                $stats['processed']++;
            } elseif ($result === 'failed') {
                $stats['failed']++;
            } elseif ($result === 'skipped') {
                $stats['skipped']++;
            }
        }
        
        $this->info("  Stats: Total={$stats['total']}, Already Processed={$stats['already_processed']}, Processed={$stats['processed']}, Skipped={$stats['skipped']}, Failed={$stats['failed']}");
    }
    
    /**
     * Process a single web order
     */
    protected function processOrder(WebOrder $order): string
    {
        $this->info("  Order: {$order->order_number} - Status: {$order->status}, Payment: {$order->payment_status}");
        
        // Only process confirmed/paid orders
        if ($order->payment_status !== 'paid' || $order->status !== 'confirmed') {
            $this->info("    Skipping - not a confirmed paid order");
            return 'skipped';
        }
        
        // Check if already has invoice and journal entry
        $hasInvoice = $order->invoice_id !== null;
        $hasJournalEntry = \App\Models\JournalEntry::where('reference', $order->order_number)
            ->where('type', 'payment')
            ->exists();
        $hasStockDeduction = StockMovement::where('reference', $order->order_number)
            ->where('type', 'out')
            ->exists();
        
        if ($hasInvoice && $hasJournalEntry && $hasStockDeduction) {
            $this->info("    Already has invoice, journal entry, and stock deduction");
            return 'already_processed';
        }
        
        try {
            // Create invoice if needed
            if (!$hasInvoice) {
                $invoice = $order->convertToInvoice();
                $this->info("    Created invoice: {$invoice->invoice_number}");
                $order->refresh();
            }
            
            // Create journal entry if needed
            if (!$hasJournalEntry) {
                $transaction = PaymentTransaction::where('transaction_id', $order->payment_transaction_id)
                    ->orWhereJsonContains('metadata->order_id', $order->id)
                    ->first();
                
                if ($transaction) {
                    $postingService = app(\Modules\Ecommerce\Services\WebOrderPostingService::class);
                    $paymentMethod = $transaction->payment_method ?? $transaction->gateway->gateway_name ?? 'card';
                    
                    $entry = $postingService->postPaidOrder(
                        $order,
                        (float) $order->total,
                        $paymentMethod
                    );
                    
                    if ($entry) {
                        $this->info("    Created journal entry: {$entry->entry_number}");
                    }
                } else {
                    $this->warn("    No payment transaction found for journal entry");
                    
                    // Still try to post without transaction
                    $postingService = app(\Modules\Ecommerce\Services\WebOrderPostingService::class);
                    $entry = $postingService->postPaidOrder(
                        $order,
                        (float) $order->total,
                        'card'
                    );
                    
                    if ($entry) {
                        $this->info("    Created journal entry: {$entry->entry_number}");
                    }
                }
            } else {
                // Journal entry exists but stock might not be deducted
                if (!$hasStockDeduction) {
                    $postingService = app(\Modules\Ecommerce\Services\WebOrderPostingService::class);
                    $postingService->deductStock($order);
                    $this->info("    Stock deducted for order");
                }
            }
            
            return 'processed';
            
        } catch (\Exception $e) {
            $this->error("    Failed: {$e->getMessage()}");
            Log::error('Failed to process web order', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'error' => $e->getMessage(),
            ]);
            return 'failed';
        }
    }
}
