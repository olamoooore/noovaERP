<?php

namespace Modules\OCRBookkeeping\Helpers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TenantUserHelper
{
    /**
     * Resolve the tenant user ID from the currently authenticated (central) user.
     * The central and tenant databases may assign different IDs to the same person.
     *
     * Returns null if no matching tenant user is found rather than falling back to
     * the central user ID, which could collide with a different tenant user.
     */
    public static function getTenantUserId(): ?int
    {
        $authUser = Auth::user();
        if (!$authUser) {
            return null;
        }

        $tenantUser = DB::table('users')->where('email', $authUser->email)->first();

        return $tenantUser?->id;
    }

    public static function resolveTenantUserId(?int $preferredUserId = null): ?int
    {
        $tenantUserId = static::getTenantUserId();
        if ($tenantUserId) {
            return $tenantUserId;
        }

        if ($preferredUserId && DB::table('users')->where('id', $preferredUserId)->exists()) {
            return $preferredUserId;
        }

        return DB::table('users')->orderBy('id')->value('id');
    }

    public static function requireTenantUserId(?int $preferredUserId = null): int
    {
        $tenantUserId = static::resolveTenantUserId($preferredUserId);

        if (!$tenantUserId) {
            throw new \RuntimeException('No tenant user is available for audit attribution.');
        }

        return (int) $tenantUserId;
    }
}
