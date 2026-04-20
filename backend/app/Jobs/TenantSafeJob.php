<?php

namespace App\Jobs;

use Illuminate\Support\Facades\Log;

/**
 * Tenant-safe job trait for background jobs
 *
 * Ensures:
 * - Tenant context is explicitly required
 * - Jobs fail closed when tenant is missing
 * - Job uniqueness includes tenant ID
 */
trait TenantSafeJob
{
    protected ?string $requiredTenantId = null;

    /**
     * Get the tenant ID for this job
     */
    abstract protected function getTenantId(): ?string;

    /**
     * Execute the job with tenant validation
     */
    public function handleWithTenantValidation(callable $handler): void
    {
        $tenantId = $this->getTenantId();

        if (empty($tenantId)) {
            Log::error('Tenant-safe job blocked: no tenant context', [
                'job' => static::class,
                'payload' => $this->payload ?? [],
            ]);

            throw new \RuntimeException(
                'Tenant context is required for ' . static::class . '. Job rejected - tenant enumeration is not allowed.'
            );
        }

        Log::debug('Tenant-safe job starting', [
            'job' => static::class,
            'tenant_id' => $tenantId,
        ]);

        $handler();
    }

    /**
     * Build a tenant-scoped unique ID for this job
     */
    protected function buildUniqueId(string $baseId): string
    {
        $tenantId = $this->getTenantId() ?? 'no-tenant';

        return $tenantId . ':' . $baseId;
    }

    /**
     * Get tags including tenant context
     */
    public function tagsWithTenant(): array
    {
        $tags = method_exists($this, 'tags') ? $this->tags() : [];
        $tenantId = $this->getTenantId();

        if ($tenantId) {
            $tags[] = 'tenant:' . $tenantId;
        }

        return $tags;
    }
}
