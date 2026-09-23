<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Services\RecurringSalesService;

/**
 * Issue #151: generate the next order for every due sales-order recurrence,
 * per tenant (schema-per-tenant).
 */
class GenerateRecurringSalesOrders extends Command
{
    protected $signature = 'sales:generate-recurring-orders {--as-of= : Generation date (defaults to today)}';

    protected $description = 'Generate sales orders for due recurring/subscription agreements';

    public function handle(RecurringSalesService $service): int
    {
        $asOf = $this->option('as-of')
            ? \Carbon\Carbon::parse($this->option('as-of'))
            : now();

        $total = 0;

        foreach (Tenant::all() as $tenant) {
            tenancy()->initialize($tenant);

            try {
                $generated = $service->generateDue($asOf);
                $total += count($generated);

                if ($generated !== []) {
                    $this->info("Tenant {$tenant->id}: generated ".count($generated).' recurring order(s).');
                }
            } catch (\Throwable $e) {
                Log::warning('Recurring sales generation failed for tenant', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("Tenant {$tenant->id}: {$e->getMessage()}");
            } finally {
                tenancy()->end();
            }
        }

        $this->info("Recurring sales generation complete: {$total} order(s).");

        return self::SUCCESS;
    }
}
