<?php

declare(strict_types=1);

namespace Modules\Sales\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Platform\Workflow\WorkflowDeniedException;
use App\Services\SalesWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Issue #335 (Q6) — mark past-due quotations expired.
 *
 * A quotation is due when its `valid_until` date is before today. Only open
 * quotations (draft/sent) are expired; confirmed quotations have already been
 * accepted and cancelled ones are terminal. Every transition runs through the
 * shared #900 WorkflowEngine, so it lands on `workflow_logs` and the audit
 * trail, and emits `quotation.expired.v1` (#901) through the outbox. The
 * command is idempotent: a rerun finds no remaining due draft/sent rows.
 */
class ExpireQuotations extends Command
{
    use IteratesTenants;

    protected $signature = 'quotations:expire
        {--tenant= : Run for a single tenant id instead of iterating every tenant}';

    protected $description = 'Mark past-due draft/sent quotations as expired through the sales workflow engine (#335)';

    public function handle(): int
    {
        $tenantId = (string) ($this->option('tenant') ?? '');

        if ($tenantId !== '') {
            $tenant = Tenant::find($tenantId);

            if ($tenant !== null && $this->schemaExists($tenant->getSchemaName())) {
                return (int) $tenant->run(fn () => $this->expireTenant($tenantId));
            }

            return $this->expireTenant((string) ($tenant?->getTenantKey() ?? $tenantId));
        }

        return $this->forEachTenant(fn (Tenant $tenant) => $this->expireTenant((string) $tenant->getTenantKey()));
    }

    private function expireTenant(string $tenantId): int
    {
        $due = Quotation::query()
            ->whereIn('status', ['draft', 'sent'])
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now()->startOfDay()->toDateString())
            ->orderBy('id')
            ->get();

        $expired = 0;

        foreach ($due as $quotation) {
            try {
                app(SalesWorkflowService::class)->transition(
                    $quotation,
                    'expire',
                    ['valid_until' => $quotation->valid_until?->toDateString()],
                    'Validity date passed without acceptance',
                );
                $expired++;
            } catch (WorkflowDeniedException $e) {
                // A status raced between the query and the transition (e.g. a
                // concurrent confirm): skip it, never write around the engine.
                Log::info('ExpireQuotations: transition denied, quotation left untouched', [
                    'quotation_id' => $quotation->id,
                    'status' => $quotation->status,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        $this->info("[tenant:{$tenantId}] Quotation expiry scan: {$due->count()} due quotation(s) checked, {$expired} expired.");

        return 0;
    }
}
