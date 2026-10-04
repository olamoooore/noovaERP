<?php

namespace App\Console\Commands;

use App\Models\LegalEntity;
use App\Models\Tenant;
use App\Services\Reconciliation\ARControlAccountNotConfiguredException;
use App\Services\Reconciliation\ARGLReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Issue #881 (SA8) — scheduled, per-legal-entity AR subledger vs GL AR
 * control-account reconciliation (the AR sibling of `inventory:reconcile-gl`).
 *
 * A missing accounts_receivable mapping is a setup error: the run reports it
 * and exits non-zero instead of reconciling against a guessed account code.
 */
class ReconcileArGl extends Command
{
    protected $signature = 'accounting:reconcile-ar-gl
                            {--as-of= : Reconciliation date (defaults to today)}
                            {--entity= : Reconcile only this legal entity id}
                            {--tolerance=0.01 : Absolute variance treated as reconciled}
                            {--fail-on-variance : Exit non-zero when any entity is out of tolerance}';

    protected $description = 'Reconcile the AR subledger against the GL AR control account, per legal entity';

    public function handle(ARGLReconciliationService $service): int
    {
        $asOf = (string) ($this->option('as-of') ?: now()->toDateString());
        $tolerance = (float) $this->option('tolerance');
        $onlyEntity = ($this->option('entity') !== null && $this->option('entity') !== '')
            ? (int) $this->option('entity')
            : null;

        $hasVariance = false;
        $hasSetupError = false;

        foreach (Tenant::all() as $tenant) {
            tenancy()->initialize($tenant);

            try {
                $entities = LegalEntity::query()
                    ->orderBy('id')
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                if ($onlyEntity !== null) {
                    $entities = array_values(array_intersect($entities, [$onlyEntity]));
                }

                // Zero-entity tenants reconcile tenant-wide once.
                $targets = $entities !== [] ? $entities : [null];

                foreach ($targets as $entityId) {
                    try {
                        $result = $service->reconcile($asOf, null, $entityId, $tolerance);

                        if ($result->isPassed()) {
                            $this->info(sprintf(
                                'Tenant %s / entity %s: reconciled (%.2f).',
                                $tenant->id,
                                $entityId ?? 'all',
                                (float) $result->subledger_balance,
                            ));
                        } else {
                            $hasVariance = true;
                            $this->warn(sprintf(
                                'Tenant %s / entity %s: variance %.2f (subledger %.2f vs GL %.2f).',
                                $tenant->id,
                                $entityId ?? 'all',
                                (float) $result->difference,
                                (float) $result->subledger_balance,
                                (float) $result->gl_balance,
                            ));
                        }
                    } catch (ARControlAccountNotConfiguredException $e) {
                        $hasSetupError = true;
                        Log::error('AR reconciliation setup error', [
                            'tenant_id' => $tenant->id,
                            'legal_entity_id' => $entityId,
                            'error' => $e->getMessage(),
                        ]);
                        $this->error(sprintf(
                            'Tenant %s / entity %s: %s',
                            $tenant->id,
                            $entityId ?? 'all',
                            $e->getMessage(),
                        ));
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('AR reconciliation failed for tenant', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("Tenant {$tenant->id}: {$e->getMessage()}");
            } finally {
                tenancy()->end();
            }
        }

        if ($hasSetupError) {
            return self::FAILURE;
        }

        return $hasVariance && $this->option('fail-on-variance') ? self::FAILURE : self::SUCCESS;
    }
}
