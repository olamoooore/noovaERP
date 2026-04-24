<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\PosOrder;
use Modules\POS\Services\PosPaymentCompletionService;
use Modules\Sales\Services\SalesOrderService;

class GenerateMissingPosInvoices extends Command
{
    public function __construct(
        private readonly PosPaymentCompletionService $posPaymentCompletionService,
    ) {
        parent::__construct();
    }

    /**
     * The name and signature of the console command.
     */
    protected $signature = 'pos:generate-invoices {--tenant= : Specific tenant ID (required)}';

    /**
     * The console command description.
     */
    protected $description = 'Generate invoices for paid POS orders that don\'t have invoices';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tenantId = $this->option('tenant');

        if (!$tenantId) {
            $this->error('The --tenant flag is required for this command.');
            $this->info('Usage: php artisan pos:generate-invoices --tenant=<tenant-id>');
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
     * Process tenant orders.
     */
    protected function processTenantOrders()
    {
        $this->info('Finding paid POS orders without invoices...');

        // Authenticate as system user for proper audit trail (required by SalesOrderService)
        $systemUser = User::first();
        if ($systemUser) {
            auth()->login($systemUser);
        } else {
            $this->error('No users found in the system. Cannot run without an authenticated user.');
            return;
        }

        // Get all paid POS orders
        $paidOrders = PosOrder::where('status', 'paid')
            ->with(['items.product', 'customer'])
            ->get();

        // Detect existing invoices via pos_order_id FK
        $existingViaFK = Invoice::whereNotNull('pos_order_id')
            ->pluck('pos_order_id')
            ->toArray();

        // Detect existing invoices via notes pattern (legacy invoices before FK was set)
        $existingViaNotes = Invoice::where('notes', 'like', '%POS Order:%')
            ->pluck('notes')
            ->map(function ($note) {
                preg_match('/POS Order:?\s*#?([A-Z0-9-]+)/', $note, $matches);
                return $matches[1] ?? null;
            })
            ->filter()
            ->toArray();

        $ordersWithoutInvoices = $paidOrders->filter(function ($order) use ($existingViaFK, $existingViaNotes) {
            return !in_array($order->id, $existingViaFK)
                && !in_array($order->order_number, $existingViaNotes);
        });

        if ($ordersWithoutInvoices->isEmpty()) {
            $this->info('All paid POS orders already have invoices.');
            return 0;
        }

        $this->info("Found {$ordersWithoutInvoices->count()} orders without invoices.");

        // Get or create walk-in customer
        $walkInCustomer = Customer::firstOrCreate(
            ['email' => 'walkin@system.local'],
            [
                'name' => 'Walk-in Customer',
                'phone' => null,
                'address' => null,
                'notes' => 'System customer for walk-in POS transactions',
            ]
        );

        $bar = $this->output->createProgressBar($ordersWithoutInvoices->count());
        $bar->start();

        $created = 0;
        $failed = 0;

        $salesOrderService = app(SalesOrderService::class);

        foreach ($ordersWithoutInvoices as $order) {
            try {
                DB::transaction(function () use ($order, $walkInCustomer, $salesOrderService) {
                    $order->loadMissing('items.product', 'items.variant', 'terminal.warehouse');

                    if (!$order->customer_id) {
                        $order->update(['customer_id' => $walkInCustomer->id]);
                    }

                    $salesOrder = $salesOrderService->createFromPosOrder($order);

                    if ($salesOrder->isInvoiced()) {
                        return;
                    }

                    $paymentMethod = $order->payments()->latest()->value('method') ?? 'card';
                    $paymentReference = "POS-{$order->order_number}";

                    // Delegate GL posting, payment record, and stock deduction
                    // to the canonical service — same invariants as live completion.
                    $this->posPaymentCompletionService->backfill(
                        posOrder: $order,
                        paymentMethod: $paymentMethod,
                        reference: $paymentReference,
                        notes: "POS order {$order->order_number} (pre-paid via repair)",
                    );

                    $order->refresh();

                    if ($salesOrder->isDraft()) {
                        $salesOrder = $salesOrderService->confirmOrder($salesOrder);
                    }
                    if ($salesOrder->isConfirmed()) {
                        $salesOrder = $salesOrderService->allocateOrder($salesOrder);
                    }
                    if ($salesOrder->isAllocated()) {
                        $salesOrder = $salesOrderService->fulfilOrder($salesOrder);
                    }

                    $invoice = $salesOrderService->createInvoice($salesOrder, [
                        'subtotal'        => $order->subtotal,
                        'tax_amount'      => $order->tax_amount ?? 0,
                        'discount_amount' => $order->discount_amount ?? 0,
                        'total'           => $order->total,
                        'pos_order_id'    => $order->id,
                    ]);

                    $salesOrderService->recordChannelPayment($invoice->fresh(), [
                        'payment_date' => now(),
                        'method' => $paymentMethod,
                        'reference' => "POS-{$order->order_number}",
                        'amount' => (float) $order->total,
                        'notes' => "POS order {$order->order_number} (pre-paid)",
                        'received_by' => \Modules\OCRBookkeeping\Helpers\TenantUserHelper::resolveTenantUserId($order->created_by),
                    ]);

                    $order->update(['paid_at' => $order->paid_at ?? now()]);
                });

                $created++;
            } catch (\Exception $e) {
                $this->error("\nFailed to create invoice for order {$order->order_number}: {$e->getMessage()}");
                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Created {$created} invoices.");
        if ($failed > 0) {
            $this->warn("Failed to create {$failed} invoices.");
        }

        return 0;
    }

}
