<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Tenant;
use App\Platform\Audit\AuditAnchorService;
use Illuminate\Console\Command;

/**
 * Issue #912: capture the periodic anchor — an HMAC-signed digest of the
 * current audit chain head per tenant/period.
 */
class AnchorAuditChain extends Command
{
    use IteratesTenants;

    protected $signature = 'audit:anchor
                            {--tenant= : Anchor a single tenant id instead of iterating every tenant}
                            {--period= : Period label for the anchor (defaults to today, Y-m-d)}';

    protected $description = 'Store an HMAC-signed digest of the tamper-evident audit chain head per tenant';

    public function handle(): int
    {
        $tenantId = (string) ($this->option('tenant') ?? '');
        $period = $this->option('period') !== null ? (string) $this->option('period') : null;

        if ($tenantId !== '') {
            $tenant = Tenant::find($tenantId);

            if ($tenant !== null && $this->schemaExists($tenant->getSchemaName())) {
                return (int) $tenant->run(fn () => $this->anchorTenant($tenant, $period));
            }

            // Tests and single-schema CLI contexts have no per-tenant schema
            // to switch to: anchor against the current search_path.
            return $this->anchorTenant($tenant ?? new Tenant(['id' => $tenantId]), $period);
        }

        return $this->forEachTenant(fn (Tenant $tenant) => $this->anchorTenant($tenant, $period));
    }

    private function anchorTenant(Tenant $tenant, ?string $period): int
    {
        $anchor = app(AuditAnchorService::class)->anchor($period);

        if ($anchor === null) {
            $this->info("[tenant:{$tenant->getTenantKey()}] no chained audit entries to anchor.");

            return 0;
        }

        $this->info("[tenant:{$tenant->getTenantKey()}] anchored chain sequence {$anchor->chain_sequence} for period {$anchor->period}.");

        return 0;
    }
}
