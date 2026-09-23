<?php

namespace App\Tenancy;

use App\Exceptions\TenantSuspendedException;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Issue #212: the #172 suspension block lived only in
 * SetTenantFromUserPreference (authenticated requests). This guard is the
 * shared, central evaluator used by the TenancyInitialized listener so every
 * tenant-resolution entry point — public storefront, IoT, webhooks, queued
 * jobs, and any future caller — is covered without repeating the check.
 *
 * Issue #213: also owns the atomic read-modify-write of the shared
 * public.tenants.data json column so concurrent writers to different keys
 * cannot clobber each other (lost-update race).
 */
class TenantSuspensionGuard
{
    private static int $bypassDepth = 0;

    /**
     * Whether the tenant is marked suspended in its raw data column.
     */
    public static function isSuspended(Tenant $tenant): bool
    {
        $data = $tenant->data ?? null;

        if (is_array($data) && array_key_exists('suspended', $data)) {
            return (bool) $data['suspended'];
        }

        // The stancl/tenancy virtual `data` attribute can round-trip as NULL;
        // fall back to the raw column.
        $raw = DB::table('public.tenants')->where('id', $tenant->getTenantKey())->value('data');

        return \App\Http\Middleware\SetTenantFromUserPreference::isSuspendedFlagSet($raw);
    }

    public static function assertNotSuspended(Tenant $tenant): void
    {
        if (self::$bypassDepth > 0) {
            return;
        }

        if (self::isSuspended($tenant)) {
            throw new TenantSuspendedException((string) $tenant->getTenantKey());
        }
    }

    /**
     * Run a callback with suspension enforcement disabled — for platform
     * maintenance operations that legitimately need to touch a suspended
     * tenant (reinstatement, deletion, guardrail tooling).
     */
    public static function withoutEnforcement(callable $callback): mixed
    {
        self::$bypassDepth++;

        try {
            return $callback();
        } finally {
            self::$bypassDepth--;
        }
    }

    /**
     * Issue #213: atomically merge keys into public.tenants.data in a single
     * UPDATE, so a concurrent writer merging a different key cannot be lost.
     *
     * @param  array<string, mixed>  $patch
     * @param  string[]  $removeKeys
     */
    public static function mergeTenantData(string $tenantId, array $patch = [], array $removeKeys = []): void
    {
        // json_encode([]) yields '[]' — an empty patch must be an object.
        $patchJson = $patch === []
            ? '{}'
            : (json_encode($patch, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        $removeLiteral = $removeKeys === []
            ? "'{}'::text[]"
            : 'ARRAY['.implode(',', array_map(fn (string $key) => DB::getPdo()->quote($key), $removeKeys)).']::text[]';

        // The tenant `data` column is a custom-columns OBJECT; a legacy/empty
        // array root (e.g. `[]`) must be normalised first, otherwise jsonb `||`
        // concatenates arrays instead of merging objects.
        DB::statement(
            "UPDATE public.tenants
             SET data = ((
                    CASE
                        WHEN jsonb_typeof(COALESCE(data::jsonb, '{}'::jsonb)) = 'object'
                            THEN COALESCE(data::jsonb, '{}'::jsonb)
                        ELSE '{}'::jsonb
                    END || ?::jsonb
                 ) - {$removeLiteral})::json,
                 updated_at = ?
             WHERE id = ?",
            [$patchJson, now(), $tenantId]
        );
    }
}
