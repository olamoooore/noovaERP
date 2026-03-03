<?php

namespace Modules\EventManagement\Traits;

/**
 * HasTenantScope Trait
 *
 * Provides tenant-scoping helpers for EventManagement models.
 * Actual tenant isolation is handled at the PostgreSQL schema level
 * (search_path is set per-request by the middleware stack), so this
 * trait is a lightweight companion that exposes convenience methods
 * without adding redundant global scopes.
 */
trait HasTenantScope
{
    /**
     * Boot the trait — no global scope needed because the DB connection's
     * search_path already restricts all queries to the correct schema.
     */
    public static function bootHasTenantScope(): void
    {
        // Intentionally empty: tenant isolation is handled via PostgreSQL
        // search_path set by SetTenantSearchPath / EnsureTenantSearchPath middleware.
    }

    /**
     * Scope a query to a specific tenant_id column value.
     * Use this only when you explicitly need to cross-tenant queries.
     */
    public function scopeForTenant($query, string $tenantId)
    {
        if ($query->getModel()->getConnection()->getSchemaGrammar()->tableExists ?? false) {
            return $query->where('tenant_id', $tenantId);
        }

        return $query;
    }
}
