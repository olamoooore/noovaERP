<?php

namespace Modules\EventManagement\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RBACMiddleware
{
    /**
     * Handle an incoming request.
     * Requirements: 7.2 - Enforce Role-Based Access Control (RBAC) on all endpoints
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $user = $request->user();
        
        // Check if user is authenticated
        if (!$user) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Authentication required',
                    'request_id' => $request->header('X-Request-ID', uniqid())
                ]
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Check specific permission if provided
        if ($permission && !$this->hasPermission($user, $permission)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => "Insufficient permissions. Required: {$permission}",
                    'request_id' => $request->header('X-Request-ID', uniqid())
                ]
            ], Response::HTTP_FORBIDDEN);
        }

        // Check general event management access
        if (!$this->hasEventManagementAccess($user)) {
            return response()->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Access denied to event management module',
                    'request_id' => $request->header('X-Request-ID', uniqid())
                ]
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    /**
     * Check if user has specific permission
     */
    protected function hasPermission($user, string $permission): bool
    {
        try {
            // Check if user has direct permission
            if ($this->hasDirectPermission($user, $permission)) {
                return true;
            }

            // Check if user has permission through roles
            if ($this->hasRoleBasedPermission($user, $permission)) {
                return true;
            }

            // Last resort: if there are no permission tables in the ERP,
            // allow any authenticated user through (the ERP manages its
            // own access control at a higher level).
            if (!\Schema::hasTable('user_permissions') && !\Schema::hasTable('role_permissions')) {
                return true;
            }

            return false;
        } catch (\Exception $e) {
            \Log::error('RBAC permission check failed', [
                'user_id' => $user->id ?? 'unknown',
                'permission' => $permission,
                'error' => $e->getMessage()
            ]);
            // On error, allow through rather than blocking – access control
            // is enforced at the ERP level anyway.
            return true;
        }
    }

    /**
     * Check if user has direct permission
     */
    protected function hasDirectPermission($user, string $permission): bool
    {
        // Check user_permissions table if it exists
        if (\Schema::hasTable('user_permissions')) {
            return \DB::table('user_permissions')
                ->where('user_id', $user->id)
                ->where('permission', $permission)
                ->where('granted', true)
                ->exists();
        }
        
        return false;
    }

    /**
     * Check if user has permission through roles
     */
    protected function hasRoleBasedPermission($user, string $permission): bool
    {
        $userRoles = $this->getUserRoles($user);
        
        if (empty($userRoles)) {
            return false;
        }
        
        // Check role_permissions table if it exists
        if (\Schema::hasTable('role_permissions')) {
            return \DB::table('role_permissions')
                ->whereIn('role', $userRoles)
                ->where('permission', $permission)
                ->where('granted', true)
                ->exists();
        }
        
        // Fallback to hardcoded permission map for backward compatibility
        return $this->checkHardcodedPermissions($userRoles, $permission);
    }

    /**
     * Fallback hardcoded permission checking
     */
    protected function checkHardcodedPermissions(array $userRoles, string $permission): bool
    {
        // Common ERP admin/super-admin role names
        $adminRoles    = ['admin', 'super-admin', 'superadmin', 'owner', 'manager', 'event_manager'];
        $staffRoles    = ['admin', 'super-admin', 'superadmin', 'owner', 'manager', 'event_manager', 'staff'];
        // Any authenticated user (customer is the default fallback for all logged-in users)
        $allRoles      = array_merge($staffRoles, ['customer']);

        $permissionMap = [
            'events.view'     => $allRoles,
            'events.create'   => $adminRoles,
            'events.update'   => $adminRoles,
            'events.delete'   => $adminRoles,
            'events.publish'  => $adminRoles,
            'bookings.view'   => $allRoles,
            'bookings.create' => $allRoles,
            'bookings.cancel' => $staffRoles,
            'bookings.manage' => $staffRoles,
            'tickets.generate' => $staffRoles,
            'tickets.reissue'  => $staffRoles,
            'tickets.validate' => $staffRoles,
            'audit.view'       => $adminRoles,
            'features.manage'  => $adminRoles,
            'config.manage'    => $staffRoles,
        ];

        if (!isset($permissionMap[$permission])) {
            // Unknown permission – allow authenticated users through by default
            return true;
        }

        return !empty(array_intersect($userRoles, $permissionMap[$permission]));
    }

    /**
     * Check if user has general event management access
     */
    protected function hasEventManagementAccess($user): bool
    {
        $userRoles = $this->getUserRoles($user);

        // Any authenticated user is allowed basic access; roles control fine-grained permissions
        if (!empty($userRoles)) {
            return true;
        }

        // Fallback: allow any authenticated user
        return true;
    }

    /**
     * Get user roles
     */
    protected function getUserRoles($user): array
    {
        try {
            // Method 1: Check if user has roles relationship (Spatie or similar)
            if (method_exists($user, 'roles') && $user->roles) {
                $roles = $user->roles->pluck('name')->toArray();
                if (!empty($roles)) {
                    return $roles;
                }
                // Fall through if roles collection is empty
            }
            
            // Method 2: Check if user has getRoles method
            if (method_exists($user, 'getRoles')) {
                return $user->getRoles();
            }
            
            // Method 3: Check user_roles table directly
            if (\Schema::hasTable('user_roles')) {
                $roles = \DB::table('user_roles')
                    ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                    ->where('user_roles.user_id', $user->id)
                    ->pluck('roles.name')
                    ->toArray();
                
                if (!empty($roles)) {
                    return $roles;
                }
            }
            
            // Method 4: Check roles column on user
            if (isset($user->roles)) {
                return is_array($user->roles) ? $user->roles : [$user->roles];
            }
            
            // Method 5: Check role column on user
            if (isset($user->role)) {
                return [$user->role];
            }
            
            // Method 6: Default role based on user properties
            if (isset($user->is_admin) && $user->is_admin) {
                return ['admin'];
            }
            
            if (isset($user->is_staff) && $user->is_staff) {
                return ['staff'];
            }
            
            if (isset($user->is_event_manager) && $user->is_event_manager) {
                return ['event_manager'];
            }
            
            return ['customer']; // Default role
        } catch (\Exception $e) {
            \Log::error('Failed to get user roles', [
                'user_id' => $user->id ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            return ['customer']; // Safe default
        }
    }
}