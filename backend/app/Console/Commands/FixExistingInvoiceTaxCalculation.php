<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;

class FixExistingInvoiceTaxCalculation extends Command
{
    protected $signature = 'invoices:fix-tax-calculation
                            {--dry-run : Show what would be changed without applying}
                            {--tenant= : Specific tenant ID to fix}';

    protected $description = 'Fix double-taxation on existing invoices by recalculating line totals';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $tenantId = $this->option('tenant');

        $query = Invoice::with('lines');
        if ($tenantId) {
            $query->where('tenant_id', $tenantId);
        }

        $invoices = $query->get();
        $this->info("Found {$invoices->count()} invoices to check.");

        $fixed = 0;
        $skipped = 0;

        foreach ($invoices as $invoice) {
            $changed = false;
            foreach ($invoice->lines as $line) {
                $oldTax = $line->tax_amount;
                $oldTotal = $line->line_total;

                // Assume POS/online orders were tax-inclusive if they came from those sources
                // We detect by checking if the invoice has a related POS order or web order
                $isTaxInclusive = $this->isLikelyTaxInclusive($invoice);
                $line->tax_inclusive = $isTaxInclusive;

                $line->calculateTotals();

                if ($line->tax_amount != $oldTax || $line->line_total != $oldTotal) {
                    $changed = true;
                    if (! $dryRun) {
                        $line->save();
                    }
                }
            }

            if ($changed) {
                $invoice->calculateTotals();
                $fixed++;
                $this->line("Fixed invoice {$invoice->invoice_number} (was subtotal: {$invoice->subtotal}, tax: {$invoice->tax_amount}, total: {$invoice->total})");
            } else {
                $skipped++;
            }
        }

        $this->info("Done. Fixed: {$fixed}, Skipped: {$skipped}");
        if ($dryRun) {
            $this->warn('This was a dry run. No changes were applied.');
        }

        return Command::SUCCESS;
    }

    private function isLikelyTaxInclusive(Invoice $invoice): bool
    {
        // POS orders and online orders typically include tax in the price
        if ($invoice->source_type === 'pos' || $invoice->source_type === 'web') {
            return true;
        }

        // Check if invoice has a related sales order from POS/web
        if ($invoice->sales_order_id) {
            $order = \Modules\Sales\Models\SalesOrder::find($invoice->sales_order_id);
            if ($order && in_array($order->channel, ['pos', 'web', 'online'])) {
                return true;
            }
        }

        // Default: manual invoices are tax-exclusive
        return false;
    }
}
