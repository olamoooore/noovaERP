<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Throwable;

class SyncTenantUserFromCentralAuth
{
    public function handle(Request $request, Closure $next)
    {
        $centralUser = $request->user();

        if (!$centralUser || !function_exists('tenant') || !tenant()) {
            return $next($request);
        }

        try {
            $tenantUser = User::query()->where('email', $centralUser->email)->first();

            if (!$tenantUser) {
                $tenantUser = new User();
                $tenantUser->email = $centralUser->email;
            }

            $tenantUser->name = $centralUser->name;

            if ($centralUser->getAuthPassword() && $tenantUser->getAuthPassword() !== $centralUser->getAuthPassword()) {
                $tenantUser->password = $centralUser->getAuthPassword();
            }

            if (!$tenantUser->exists || $tenantUser->isDirty()) {
                $tenantUser->save();
            }

            $this->syncTenantRole($tenantUser, (int) $centralUser->getAuthIdentifier());

            if (method_exists($tenantUser, 'withAccessToken') && method_exists($centralUser, 'currentAccessToken')) {
                $tenantUser->withAccessToken($centralUser->currentAccessToken());
            }

            $guards = array_unique(array_filter([
                config('auth.defaults.guard'),
                'sanctum',
                ...((array) config('sanctum.guard', [])),
            ]));

            foreach ($guards as $guardName) {
                $guard = Auth::guard($guardName);
                if (method_exists($guard, 'setUser')) {
                    $guard->setUser($tenantUser);
                }
            }

            Auth::shouldUse((string) config('auth.defaults.guard', 'web'));

            $request->setUserResolver(static fn () => $tenantUser);

            Log::info('SyncTenantUserFromCentralAuth: Resolved tenant user', [
                'tenant_id' => tenant('id'),
                'central_user_id' => $centralUser->getAuthIdentifier(),
                'tenant_user_id' => $tenantUser->id,
                'email' => $tenantUser->email,
            ]);
        } catch (Throwable $e) {
            Log::error('SyncTenantUserFromCentralAuth: Failed to resolve tenant user', [
                'tenant_id' => tenant('id'),
                'central_user_id' => $centralUser->getAuthIdentifier(),
                'central_user_email' => $centralUser->email,
                'error' => $e->getMessage(),
            ]);
        }

        return $next($request);
    }

    private function syncTenantRole(User $tenantUser, int $centralUserId): void
    {
        $tenantId = tenant('id');

        if (!$tenantId) {
            return;
        }

        $access = DB::table('public.user_company_access')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $centralUserId)
            ->first();

        if (!$access || empty($access->role)) {
            return;
        }

        $roleName = Str::title(str_replace(['-', '_'], ' ', (string) $access->role));
        $role = Role::firstOrCreate([
            'name' => $roleName,
            'guard_name' => 'web',
        ]);

        if (!$tenantUser->hasRole($role)) {
            $tenantUser->assignRole($role);
        }
    }
}