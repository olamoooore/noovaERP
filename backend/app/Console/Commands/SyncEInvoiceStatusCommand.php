<?php

namespace App\Console\Commands;

use App\Accounting\Services\ComplianceService;
use App\Models\Accounting\CountryConfiguration;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * #666 EI4: LHDN (and Peppol) validation is asynchronous — a submission moves
 * later to Valid/Invalid and a buyer can request rejection within 72 hours.
 * Nothing scheduled polled for that, so Invalid results and rejection
 * requests lapsed unnoticed.
 *
 * Runs per tenant, per enabled e-invoice country, using the same
 * ComplianceService::syncPendingSubmissions() the manual sync uses.
 */
class SyncEInvoiceStatusCommand extends Command
{
    protected $signature = 'compliance:sync-einvoice-status';

    protected $description = 'Poll each tenant\'s e-invoice provider for submission status changes (#666).';

    public function handle(): int
    {
        $total = 0;

        foreach (Tenant::all() as $tenant) {
            tenancy()->initialize($tenant);

            try {
                $countries = CountryConfiguration::query()
                    ->where('is_enabled', true)
                    ->pluck('country_code')
                    ->unique()
                    ->filter()
                    ->values();

                foreach ($countries as $country) {
                    // Scheduled sweep (#666 AC1): poll only submissions inside
                    // the polling window (recent every run, 72h–7d hourly,
                    // never past 7 days) so one sweep is a bounded number of
                    // provider calls per tenant.
                    $synced = app(ComplianceService::class)->syncPendingSubmissions((string) $country, scheduledSweep: true);
                    $total += $synced;

                    if ($synced > 0) {
                        $this->info("Tenant {$tenant->id} {$country}: synced {$synced} submission(s).");
                    }
                }
            } catch (\Throwable $e) {
                // One tenant/provider failing must not stop the sweep.
                Log::warning('E-invoice status sync failed for tenant', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("Tenant {$tenant->id}: {$e->getMessage()}");
            } finally {
                tenancy()->end();
            }
        }

        $this->info("E-invoice status sync complete: {$total} submission(s) processed.");

        return Command::SUCCESS;
    }
}
