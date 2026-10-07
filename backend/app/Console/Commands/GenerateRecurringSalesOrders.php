<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Services\RecurringSalesService;

/**
 * Issue #151: generate the next order for every due sales-order recurrence,
 * per tenant (schema-per-tenant).
 *
 * Issue #330: recurrences with document_type = 'invoice' generate invoices
 * (optionally auto-posted and auto-sent) through the same command. A failed
 * schedule is reported per recurrence and makes the run exit non-zero.
 */
class GenerateRecurringSalesOrders extends Command
{
    protected $signature = 'sales:generate-recurring-orders
        {--as-of= : Generation date (defaults to today)}
        {--tenant= : Run for a single tenant id instead of every tenant}';

    protected $description = 'Generate sales orders and invoices for due recurring/subscription agreements';

    public function handle(RecurringSalesService $service): int
    {
        $asOf = $this->option('as-of')
            ? \Carbon\Carbon::parse($this->option('as-of'))
            : now();

        $total = 0;
        $failed = 0;

        $tenantId = (string) ($this->option('tenant') ?? '');

        if ($tenantId !== '') {
            $tenant = Tenant::find($tenantId);

            if ($tenant === null) {
                $this->error("Tenant {$tenantId} not found.");

                return self::FAILURE;
            }

            if ($this->schemaExists($tenant->getSchemaName())) {
                $this->runForTenant($service, $tenant, $asOf, $total, $failed);
            } else {
                // Tests and single-schema CLI contexts have no per-tenant
                // schema to switch to: run against the current search_path
                // (same fallback as sales:send-weekly-digest).
                $this->generateForTenant($service, $tenant, $asOf, $total, $failed);
            }
        } else {
            foreach (Tenant::all() as $tenant) {
                $this->runForTenant($service, $tenant, $asOf, $total, $failed);
            }
        }

        $this->info("Recurring sales generation complete: {$total} document(s), {$failed} failure(s).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function runForTenant(RecurringSalesService $service, Tenant $tenant, \Carbon\Carbon $asOf, int &$total, int &$failed): void
    {
        tenancy()->initialize($tenant);

        try {
            $this->generateForTenant($service, $tenant, $asOf, $total, $failed);
        } catch (\Throwable $e) {
            $failed++;
            Log::warning('Recurring sales generation failed for tenant', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            $this->warn("Tenant {$tenant->id}: {$e->getMessage()}");
        } finally {
            tenancy()->end();
        }
    }

    private function generateForTenant(RecurringSalesService $service, Tenant $tenant, \Carbon\Carbon $asOf, int &$total, int &$failed): void
    {
        $generated = $service->generateDue($asOf);
        $total += count($generated);

        if ($generated !== []) {
            $this->info("Tenant {$tenant->id}: generated ".count($generated).' recurring document(s).');
        }

        foreach ($service->failures() as $failure) {
            $failed++;
            $this->warn("Tenant {$tenant->id}, recurrence {$failure['recurrence_id']}: {$failure['error']}");
        }
    }

    private function schemaExists(string $schemaName): bool
    {
        return DB::select('SELECT 1 FROM information_schema.schemata WHERE schema_name = ?', [$schemaName]) !== [];
    }
}
