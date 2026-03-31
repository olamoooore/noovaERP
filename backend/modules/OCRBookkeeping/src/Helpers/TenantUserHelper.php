<?php

namespace Modules\OCRBookkeeping\Helpers;

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
        $authUser = auth()->user();
        if (!$authUser) {
            return null;
        }

        $tenantUser = DB::table('users')->where('email', $authUser->email)->first();

        return $tenantUser?->id;
    }
}
