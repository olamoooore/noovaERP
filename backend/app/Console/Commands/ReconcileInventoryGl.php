<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Reconciliation\InventoryGLReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Issue #109 — scheduled Inventory subledger vs GL control-account
 * reconciliation (the Inventory sibling of the AR/AP reconciliation jobs).
 */
class ReconcileInventoryGl extends Command
{
    protected $signature = 'inventory:reconcile-gl
                            {--as-of= : Reconciliation date (defaults to today)}
                            {--tolerance=0.01 : Absolute variance treated as reconciled}';

    protected $description = 'Reconcile inventory valuation against the GL Inventory control account';

    public function handle(InventoryGLReconciliationService $service): int
    {
        $asOf = (string) ($this->option('as-of') ?: now()->toDateString());
        $tolerance = (float) $this->option('tolerance');

        foreach (Tenant::all() as $tenant) {
            tenancy()->initialize($tenant);

            try {
                $result = $service->reconcile($asOf, null, $tolerance);

                if (! $result['within_tolerance']) {
                    Log::warning('Inventory/GL reconciliation variance detected', [
                        'tenant_id' => $tenant->id,
                        'as_of_date' => $result['as_of_date'],
                        'inventory_valuation' => $result['inventory_valuation'],
                        'gl_inventory_balance' => $result['gl_inventory_balance'],
                        'variance' => $result['variance'],
                    ]);

                    $this->warn(sprintf(
                        'Tenant %s: variance %.2f (subledger %.2f vs GL %.2f)',
                        $tenant->id,
                        $result['variance'],
                        $result['inventory_valuation'],
                        $result['gl_inventory_balance'],
                    ));
                } else {
                    $this->info(sprintf(
                        'Tenant %s: reconciled (%.2f).',
                        $tenant->id,
                        $result['inventory_valuation'],
                    ));
                }
            } catch (\Throwable $e) {
                Log::warning('Inventory/GL reconciliation failed for tenant', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("Tenant {$tenant->id}: {$e->getMessage()}");
            } finally {
                tenancy()->end();
            }
        }

        return self::SUCCESS;
    }
}
