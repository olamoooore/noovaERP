<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\InvoiceFocLinePairingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * #1006 backfill: pair existing invoices' bonus-scheme FOC lines under their
 * paid source line (order + link). Idempotent, amount-neutral — it only
 * rewrites `sequence` and the `is_foc` / `source_line_id` pairing columns.
 *
 * Usage:
 *   php artisan invoices:pair-foc-lines --dry-run
 *   php artisan invoices:pair-foc-lines --tenant=<id>
 *   php artisan invoices:pair-foc-lines
 */
class PairInvoiceFocLinesCommand extends Command
{
    use IteratesTenants;

    protected $signature = 'invoices:pair-foc-lines
        {--tenant= : Run for a single tenant id (default: all tenants)}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Pair bonus-scheme FOC invoice lines under their paid source line (#1006)';

    public function handle(InvoiceFocLinePairingService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($tenantId = $this->option('tenant')) {
            $tenant = Tenant::find($tenantId);

            if (! $tenant) {
                $this->error("Tenant not found: {$tenantId}");

                return self::FAILURE;
            }

            return $tenant->run(fn () => $this->pairForTenant($tenant, $service, $dryRun));
        }

        return $this->forEachTenant(fn (Tenant $tenant) => $this->pairForTenant($tenant, $service, $dryRun));
    }

    private function pairForTenant(Tenant $tenant, InvoiceFocLinePairingService $service, bool $dryRun): int
    {
        $connection = DB::connection();

        if ($dryRun) {
            $connection->beginTransaction();
        }

        $scanned = 0;
        $paired = 0;

        Invoice::query()
            ->whereHas('lines', fn ($query) => $query->where('quantity', '>', 0)->where('unit_price', 0))
            ->orderBy('id')
            ->chunkById(200, function ($invoices) use ($service, &$scanned, &$paired) {
                foreach ($invoices as $invoice) {
                    $scanned++;
                    $paired += $service->normalize($invoice);
                }
            });

        if ($dryRun) {
            $connection->rollBack();
        }

        $this->info(sprintf(
            '%s Tenant %s: %d invoice(s) scanned, %d FOC line(s) %s.',
            $dryRun ? '[dry-run]' : '[done]',
            $tenant->id,
            $scanned,
            $paired,
            $dryRun ? 'would be paired' : 'paired',
        ));

        return self::SUCCESS;
    }
}
