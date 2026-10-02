<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Issue #510 (CN1) one-off data repair.
 *
 * Before the fix, posting a customer credit note decremented paid_amount (the
 * cash-refund helper), so every invoice with a posted credit note carries a
 * paid_amount that is too low by the posted credit total and a balance that
 * is too high by twice the credit. Voided notes netted themselves out at the
 * time, so only posted (non-voided) notes count here.
 *
 * For each such invoice:
 *   credited_amount := Σ posted credit-note totals
 *   paid_amount     := paid_amount + that total (undo the bad decrement)
 *   balance         := max(0, total − paid − credited)
 *   status          := derived (draft/void/cancelled left alone)
 * then recomputes the affected customers' credit_balance and outstanding.
 *
 * Best effort by design: invoices where a customer later paid the phantom
 * inflated balance cannot be fully reconstructed automatically — run with
 * --dry-run first and review the report.
 */
class RepairInvoiceCreditTrackingCommand extends Command
{
    protected $signature = 'invoices:repair-credit-tracking
                            {--dry-run : Report what would change without writing}
                            {--tenant= : Restrict to one tenant ID}';

    protected $description = 'Recompute invoice paid_amount/balance/credited_amount and customer credit after the CN1 credit-note fix (#510)';

    public function handle(): int
    {
        $tenants = $this->option('tenant')
            ? Tenant::where('id', $this->option('tenant'))->get()
            : Tenant::all();

        $dryRun = (bool) $this->option('dry-run');
        $invoicesRepaired = 0;
        $customersRefreshed = 0;

        foreach ($tenants as $tenant) {
            try {
                $result = $tenant->run(function () use ($dryRun) {
                    $invoiceIds = DB::table('customer_credit_notes')
                        ->where('status', 'posted')
                        ->whereNotNull('invoice_id')
                        ->distinct()
                        ->pluck('invoice_id');

                    $repaired = 0;
                    $touchedCustomers = [];

                    foreach ($invoiceIds as $invoiceId) {
                        $invoice = Invoice::find($invoiceId);

                        if (! $invoice) {
                            continue;
                        }

                        $postedCredit = round((float) DB::table('customer_credit_notes')
                            ->where('invoice_id', $invoiceId)
                            ->where('status', 'posted')
                            ->sum('total_amount'), 2);

                        if ($postedCredit <= 0) {
                            continue;
                        }

                        $repairedPaid = round((float) $invoice->paid_amount + $postedCredit, 2);
                        $credited = $postedCredit;
                        $balance = max(0.0, round((float) $invoice->total - $repairedPaid - $credited, 2));

                        $status = in_array($invoice->status, ['draft', 'void', 'cancelled'], true)
                            ? $invoice->status
                            : match (true) {
                                $balance > 0 && $repairedPaid > 0 => 'partial',
                                $balance > 0 => $invoice->status === 'overdue' ? 'overdue' : 'sent',
                                default => 'paid',
                            };

                        $changed = $repairedPaid !== round((float) $invoice->paid_amount, 2)
                            || $balance !== round((float) $invoice->balance, 2)
                            || $credited !== round((float) ($invoice->credited_amount ?? 0), 2)
                            || $status !== $invoice->status;

                        if ($changed) {
                            $this->line(sprintf(
                                '  %s: paid %s → %s, balance %s → %s, credited → %s, status %s → %s',
                                $invoice->invoice_number,
                                $invoice->paid_amount,
                                $repairedPaid,
                                $invoice->balance,
                                $balance,
                                $credited,
                                $invoice->status,
                                $status,
                            ));

                            if (! $dryRun) {
                                Invoice::where('id', $invoice->id)->update([
                                    'paid_amount' => $repairedPaid,
                                    'balance' => $balance,
                                    'credited_amount' => $credited,
                                    'status' => $status,
                                ]);
                            }

                            $repaired++;
                            $touchedCustomers[(int) $invoice->customer_id] = (int) $invoice->customer_id;
                        }
                    }

                    if (! $dryRun) {
                        $invoiceService = app(InvoiceService::class);

                        foreach ($touchedCustomers as $customerId) {
                            $customer = Customer::find($customerId);

                            if ($customer) {
                                $invoiceService->refreshCustomerCreditBalance($customer);
                                $customer->updateOutstanding();
                            }
                        }
                    }

                    return [$repaired, count($touchedCustomers), $dryRun ? false : ! empty($touchedCustomers)];
                });

                $invoicesRepaired += $result[0];
                $customersRefreshed += $result[1];
                $this->info("Tenant {$tenant->id}: {$result[0]} invoices ".($dryRun ? 'would be repaired' : 'repaired'));
            } catch (\Throwable $e) {
                // One bad/never-migrated tenant must not abort the whole repair.
                $this->warn("Tenant {$tenant->id}: skipped — {$e->getMessage()}");
            }
        }

        $this->info(sprintf(
            'Done%s: %d invoices, %d customers affected.',
            $dryRun ? ' (dry run — nothing written)' : '',
            $invoicesRepaired,
            $customersRefreshed,
        ));

        if (! $dryRun && $invoicesRepaired > 0) {
            $this->info('Verify with: php artisan ledger:check-integrity (trial balance + AR ↔ ledger).');
        }

        return Command::SUCCESS;
    }
}
