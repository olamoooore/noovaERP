<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Platform-owned resolver for the tenant user an action is audited against
 * (SOP §4 — shared platform service; modules must not own this).
 *
 * Resolution order:
 *   1. the tenant user matching the authenticated central user's email;
 *   2. the caller's preferred user id, when it is a real tenant user;
 *   3. interactive context (an authenticated human whose tenant user cannot
 *      be resolved): null — fail closed; never borrow another identity;
 *   4. non-interactive context (queue, schedule, webhook, console): the
 *      tenant's dedicated system actor (#878).
 *
 * There is deliberately NO "first users row" fallback: issue #878 showed it
 * silently attributed background postings to the lowest-ID user (usually the
 * owner), poisoning the audit trail and defeating SoD checks.
 */
class TenantUserHelper
{
    /**
     * Reserved email of the per-tenant system actor. The actor is unique per
     * tenant schema via a partial unique index on users.is_system.
     */
    public const SYSTEM_ACTOR_EMAIL = 'system+background@noovaerp.internal';

    public const SYSTEM_ACTOR_NAME = 'System (automated postings)';

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
        if (! $authUser) {
            return null;
        }

        $tenantUser = DB::table('users')->where('email', $authUser->email)->first();

        return $tenantUser?->id;
    }

    public static function resolveTenantUserId(?int $preferredUserId = null): ?int
    {
        $tenantUserId = static::getTenantUserId();
        if ($tenantUserId) {
            return (int) $tenantUserId;
        }

        if ($preferredUserId && DB::table('users')->where('id', $preferredUserId)->exists()) {
            return (int) $preferredUserId;
        }

        // An authenticated human whose tenant row cannot be resolved must NOT
        // have their action attributed to anyone else (interactive fail-closed).
        if (Auth::check()) {
            return null;
        }

        // Non-interactive caller (queued job, schedule, webhook, console):
        // attribute to the tenant's dedicated system actor.
        return static::systemActorId();
    }

    public static function requireTenantUserId(?int $preferredUserId = null): int
    {
        $tenantUserId = static::resolveTenantUserId($preferredUserId);

        if (! $tenantUserId) {
            throw new \RuntimeException('No tenant user is available for audit attribution.');
        }

        return (int) $tenantUserId;
    }

    /**
     * Get (creating on first use) the tenant's dedicated system actor.
     *
     * The actor is a real tenant `users` row (journal_entries.posted_by is a
     * FK to users) marked with `is_system = true`. Creation is idempotent and
     * safe under concurrency (partial unique index on is_system = true).
     */
    public static function systemActorId(): ?int
    {
        try {
            $existing = DB::table('users')->where('is_system', true)->orderBy('id')->value('id');
            if ($existing) {
                return (int) $existing;
            }

            $id = DB::table('users')->insertGetId([
                'name' => self::SYSTEM_ACTOR_NAME,
                'email' => self::SYSTEM_ACTOR_EMAIL,
                'password' => Hash::make(Str::random(64)),
                'is_active' => true,
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return (int) $id;
        } catch (\Throwable $e) {
            // Unique race with a concurrent worker: return the winner's row.
            try {
                $existing = DB::table('users')->where('is_system', true)->orderBy('id')->value('id');
                if ($existing) {
                    return (int) $existing;
                }
            } catch (\Throwable) {
                // fall through to the logged failure below
            }

            Log::error('TenantUserHelper: unable to provision the tenant system actor', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public static function isSystemActor(?int $userId): bool
    {
        if (! $userId) {
            return false;
        }

        try {
            return DB::table('users')->where('id', $userId)->where('is_system', true)->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
