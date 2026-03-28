<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stancl\Tenancy\Tenancy;

class SetTenantFromUserPreference
{
    protected $tenancy;

    public function __construct(Tenancy $tenancy)
    {
        $this->tenancy = $tenancy;
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        
        if ($user && !($user->is_super_admin ?? false)) {
            // Get user's default company
            $defaultCompany = DB::table('user_company_access')
                ->where('user_id', $user->id)
                ->where('is_default', true)
                ->first();

            if (!$defaultCompany) {
                Log::warning('SetTenantFromUserPreference: No tenant mapping for user', [
                    'user_id' => $user->id,
                ]);
                return response()->json([
                    'message' => 'No tenant configured for this user.',
                ], 403);
            }

            $tenant = \App\Models\Tenant::find($defaultCompany->tenant_id);
            
            if (!$tenant) {
                Log::error('SetTenantFromUserPreference: Tenant not found', [
                    'user_id' => $user->id,
                    'tenant_id' => $defaultCompany->tenant_id,
                ]);
                return response()->json([
                    'message' => 'Tenant not found.',
                ], 403);
            }

            Log::info('SetTenantFromUserPreference: Initializing tenant', [
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
            ]);
            
            $this->tenancy->initialize($tenant);
        }

        return $next($request);
    }
}
