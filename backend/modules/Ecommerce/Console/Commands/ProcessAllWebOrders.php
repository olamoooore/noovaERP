<?php

namespace Modules\Ecommerce\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Ecommerce\Models\WebOrder;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;
use App\Models\Tenant;
use App\Models\StockMovement;
use App\Models\JournalEntryLink;

class ProcessAllWebOrders extends Command
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
    protected $signature = 'orders:process-all-web {--tenant= : Specific tenant ID (required)}';

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
        
        if (!$tenantId) {
            $this->error('The --tenant flag is required for this command.');
            $this->info('Usage: php artisan orders:process-all-web --tenant=<tenant-id>');
            return Command::FAILURE;
        }

        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            $this->error("Tenant not found: {$tenantId}");
            return Command::FAILURE;
        }
        
        $this->info("Processing tenant: {$tenant->id}");
            
        $tenant->run(function () {
            $this->processTenantOrders();
        });

        $this->info('Done!');
        return Command::SUCCESS;
    }
    
    /**
     * Process orders for a single tenant
     */
    protected function processTenantOrders()
    {
        // Check if web_orders table exists
        if (!Schema::hasTable('web_orders')) {
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
        $hasStockDeduction = StockMovement::where('reference_type', 'WebOrder')
            ->where('reference_id', $order->id)
            ->where('type', 'sale')
            ->exists();
        $hasPostingLink = JournalEntryLink::where('source_document_type', 'web_order')
            ->where('source_document_id', $order->id)
            ->where('relation_type', 'post')
            ->exists();

        if ($hasInvoice && $hasJournalEntry && $hasStockDeduction && $hasPostingLink) {
            $this->info("    Already has invoice, journal entry, and stock deduction");
            return 'already_processed';
        }
        
        try {
            $transaction = PaymentTransaction::where('transaction_id', $order->payment_transaction_id)
                ->orWhereJsonContains('metadata->order_id', $order->id)
                ->first();

            if (!$hasJournalEntry) {
                if (!$transaction) {
                    $this->warn("    No payment transaction found for canonical completion");
                    return 'skipped';
                }

                $this->completionService->complete(
                    $transaction,
                    $transaction->payment_method ?? $transaction->gateway->gateway_name ?? 'card'
                );

                $order->refresh();

                $postingLink = JournalEntryLink::where('source_document_type', 'web_order')
                    ->where('source_document_id', $order->id)
                    ->where('relation_type', 'post')
                    ->first();

                if ($postingLink) {
                    $this->info("    Canonical completion created journal entry link: {$postingLink->journal_entry_id}");
                }
            } else {
                // Journal entry exists but stock might not be deducted.
                // Route through canonical completion service to ensure audit trail and proper transaction handling.
                if (!$hasStockDeduction && $hasPostingLink && $transaction) {
                    try {
                        $this->completionService->complete(
                            $transaction,
                            $transaction->payment_method ?? $transaction->gateway->gateway_name ?? 'card'
                        );
                        $this->info("    Stock deducted via canonical completion service");
                    } catch (\Exception $e) {
                        $this->warn("    Stock deduction failed: {$e->getMessage()}");
                        throw $e;
                    }
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
