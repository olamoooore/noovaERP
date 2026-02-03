<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Modules\POS\Models\PosOrder;

class GenerateMissingPosInvoices extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'pos:generate-invoices';

    /**
     * The console command description.
     */
    protected $description = 'Generate invoices for paid POS orders that don\'t have invoices';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Finding paid POS orders without invoices...');

        // Get all paid POS orders
        $paidOrders = PosOrder::where('status', 'paid')
            ->with(['items.product', 'customer'])
            ->get();

        $existingInvoiceNotes = Invoice::where('notes', 'like', 'Generated from POS Order:%')
            ->pluck('notes')
            ->map(function ($note) {
                preg_match('/POS Order: ([A-Z0-9-]+)/', $note, $matches);
                return $matches[1] ?? null;
            })
            ->filter()
            ->toArray();

        $ordersWithoutInvoices = $paidOrders->filter(function ($order) use ($existingInvoiceNotes) {
            return !in_array($order->order_number, $existingInvoiceNotes);
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

        foreach ($ordersWithoutInvoices as $order) {
            try {
                $customerId = $order->customer_id ?? $walkInCustomer->id;

                // Create invoice
                $invoice = Invoice::create([
                    'customer_id' => $customerId,
                    'invoice_date' => $order->paid_at ?? $order->created_at,
                    'due_date' => $order->paid_at ?? $order->created_at,
                    'status' => 'paid',
                    'subtotal' => $order->subtotal,
                    'tax_amount' => $order->tax_amount,
                    'discount_amount' => $order->discount_amount ?? 0,
                    'total' => $order->total,
                    'paid_amount' => $order->total,
                    'balance' => 0,
                    'notes' => "Generated from POS Order: {$order->order_number}",
                    'created_by' => $order->created_by,
                ]);

                // Create invoice lines from POS order items
                foreach ($order->items as $item) {
                    $description = $item->product_name ?? $item->product?->name ?? 'Product';
                    $invoice->lines()->create([
                        'product_id' => $item->product_id,
                        'description' => $description,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                        'tax_rate' => 0,
                        'tax_amount' => $item->tax_amount > 0 ? $item->tax_amount / $item->quantity : 0,
                        'discount_amount' => 0,
                        'line_total' => $item->total_price,
                    ]);
                }

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
