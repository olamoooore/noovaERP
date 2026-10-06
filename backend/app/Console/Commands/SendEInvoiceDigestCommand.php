<?php

namespace App\Console\Commands;

use App\Accounting\Services\ComplianceService;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * #667 (EI5) AC2: a daily digest of posted invoices whose e-invoice is not
 * yet Valid (queued, failed, rejected, awaiting validation), sent to each
 * tenant's finance users — so an auto-submit that never made it is seen even
 * when nobody opens the invoice.
 */
class SendEInvoiceDigestCommand extends Command
{
    protected $signature = 'compliance:send-einvoice-digest';

    protected $description = 'Send finance the daily digest of posted invoices whose e-invoice is not yet Valid (#667).';

    public function handle(): int
    {
        $exit = Command::SUCCESS;

        foreach (Tenant::all() as $tenant) {
            tenancy()->initialize($tenant);

            try {
                $listed = app(ComplianceService::class)->sendUnvalidatedInvoiceDigest();

                if ($listed > 0) {
                    $this->info("Tenant {$tenant->id}: {$listed} invoice(s) not yet Valid.");
                }
            } catch (\Throwable $e) {
                // One tenant failing must not stop the others — but the run
                // reports failure rather than claiming success.
                Log::error('E-invoice digest failed for tenant', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Tenant {$tenant->id}: {$e->getMessage()}");
                $exit = Command::FAILURE;
            } finally {
                tenancy()->end();
            }
        }

        return $exit;
    }
}
