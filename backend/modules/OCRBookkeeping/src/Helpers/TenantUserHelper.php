<?php

namespace Modules\OCRBookkeeping\Helpers;

use Illuminate\Support\Facades\DB;

class TenantUserHelper
{
    /**
     * Resolve the tenant user ID from the currently authenticated (central) user.
     * The central and tenant databases may assign different IDs to the same person.
     */
    public static function getTenantUserId(): ?int
    {
        $authUser = auth()->user();
        if (!$authUser) {
            return null;
        }

        $tenantUser = DB::table('users')->where('email', $authUser->email)->first();

        return $tenantUser?->id ?? $authUser->id;
    }
}
