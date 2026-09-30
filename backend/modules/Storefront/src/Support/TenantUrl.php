<?php

namespace Modules\Storefront\Support;

use Illuminate\Support\Facades\DB;

/**
 * Best-effort public URL for tenant emails (verification, order
 * confirmation). Resolution order mirrors the platform's own public
 * resolution thinking: the tenant's first registered domain, falling back
 * to APP_URL. Token material NEVER goes in the path — query params only,
 * so links stay non-enumerable and logs stay sane.
 */
final class TenantUrl
{
    public static function base(): string
    {
        $tenant = function_exists('tenant') && tenant() ? tenant() : null;

        if ($tenant !== null) {
            try {
                $row = DB::table('public.domains')
                    ->where('tenant_id', $tenant->getTenantKey())
                    ->orderByDesc('is_primary')
                    ->orderBy('id')
                    ->first();

                if ($row !== null && is_string($row->domain) && $row->domain !== '') {
                    // FR-PLAT-03 SSL status feeds link generation: an active
                    // certificate always links https; a FAILED probe links
                    // http (honest — https would only bounce the customer);
                    // 'unknown' keeps the historical best-effort guess.
                    $scheme = match ($row->ssl_status ?? 'unknown') {
                        'active' => 'https',
                        'failed' => 'http',
                        default => str_contains($row->domain, 'localhost') || app()->environment('local')
                            ? 'http'
                            : 'https',
                    };

                    return rtrim($scheme.'://'.$row->domain, '/');
                }
            } catch (\Throwable) {
                // domain lookup failure must not block the email — fall through
            }
        }

        return rtrim((string) config('app.url'), '/');
    }

    public static function confirmation(string $token): string
    {
        return self::base().'/?sfconf='.rawurlencode($token);
    }

    public static function verify(string $token, string $email): string
    {
        return self::base().'/?sfverify='.rawurlencode($token).'&sfemail='.rawurlencode($email);
    }

    public static function reset(string $token, string $email): string
    {
        return self::base().'/?sfreset='.rawurlencode($token).'&sfemail='.rawurlencode($email);
    }
}
