<?php

namespace App\Console\Commands;

use App\Console\Concerns\IteratesTenants;
use App\Models\Tenant;
use App\Platform\Audit\AuditChainVerificationResult;
use App\Platform\Audit\AuditChainVerifier;
use App\Platform\Exceptions\ExceptionService;
use Illuminate\Console\Command;

/**
 * Issue #912: walk the tamper-evident audit hash chain for every tenant and
 * queue a platform exception (#902's ExceptionService, the existing alerting
 * path) when any stored entry no longer matches the chain.
 */
class VerifyAuditChain extends Command
{
    use IteratesTenants;

    protected $signature = 'audit:verify-chain
                            {--tenant= : Verify a single tenant id instead of iterating every tenant}';

    protected $description = 'Verify the tamper-evident audit hash chain per tenant and raise platform exceptions on breaks';

    public function handle(): int
    {
        $tenantId = (string) ($this->option('tenant') ?? '');

        if ($tenantId !== '') {
            $tenant = Tenant::find($tenantId);

            if ($tenant !== null && $this->schemaExists($tenant->getSchemaName())) {
                return (int) $tenant->run(fn () => $this->verifyTenant($tenant));
            }

            // Tests and single-schema CLI contexts have no per-tenant schema
            // to switch to: verify against the current search_path.
            return $this->verifyTenant($tenant ?? new Tenant(['id' => $tenantId]));
        }

        return $this->forEachTenant(fn (Tenant $tenant) => $this->verifyTenant($tenant));
    }

    private function verifyTenant(Tenant $tenant): int
    {
        $result = app(AuditChainVerifier::class)->verify();
        $key = $tenant->getTenantKey();

        if ($result->ok) {
            $this->info("[tenant:{$key}] audit chain verified ({$result->checked} entries, {$result->legacySkipped} legacy rows skipped, {$result->anchorsChecked} anchors).");

            return 0;
        }

        $this->error("[tenant:{$key}] audit chain BROKEN ({$result->checked} entries checked, {$result->legacySkipped} legacy rows skipped).");

        foreach ($result->failures as $failure) {
            $this->line(sprintf(
                ' - [%s] %s#%s (seq %s): %s',
                $failure['type'],
                $failure['source'],
                $failure['entry_id'] ?? '?',
                $failure['sequence'] ?? '?',
                $failure['message'],
            ));
        }

        $this->raisePlatformException($tenant, $result);

        return 1;
    }

    private function raisePlatformException(Tenant $tenant, AuditChainVerificationResult $result): void
    {
        try {
            app(ExceptionService::class)->log(
                'platform',
                'audit.chain.broken',
                'critical',
                "Tamper-evident audit chain verification failed for tenant {$tenant->getTenantKey()}",
                [
                    'tenant_id' => $tenant->getTenantKey(),
                    'checked' => $result->checked,
                    'legacy_skipped' => $result->legacySkipped,
                    'head_sequence' => $result->headSequence,
                    'failures' => array_slice($result->failures, 0, 25),
                ],
            );
        } catch (\Throwable $e) {
            $this->error("[tenant:{$tenant->getTenantKey()}] could not queue platform exception: {$e->getMessage()}");
        }
    }
}
