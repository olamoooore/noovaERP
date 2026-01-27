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
            
            return false;
        } catch (\Exception $e) {
            // Log error but deny access for security
            \Log::error('RBAC permission check failed', [
                'user_id' => $user->id ?? 'unknown',
                'permission' => $permission,
                'error' => $e->getMessage()
            ]);
            return false;
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
        $permissionMap = [
            'events.view' => ['admin', 'event_manager', 'staff'],
            'events.create' => ['admin', 'event_manager'],
            'events.update' => ['admin', 'event_manager'],
            'events.delete' => ['admin'],
            'events.publish' => ['admin', 'event_manager'],
            'bookings.view' => ['admin', 'event_manager', 'staff'],
            'bookings.create' => ['admin', 'event_manager', 'staff', 'customer'],
            'bookings.cancel' => ['admin', 'event_manager'],
            'bookings.manage' => ['admin', 'event_manager'],
            'tickets.generate' => ['admin', 'event_manager'],
            'tickets.reissue' => ['admin', 'event_manager', 'staff'],
            'tickets.validate' => ['admin', 'event_manager', 'staff'],
            'audit.view' => ['admin'],
            'features.manage' => ['admin'],
            'config.manage' => ['admin', 'event_manager'],
        ];

        if (!isset($permissionMap[$permission])) {
            return false;
        }

        return !empty(array_intersect($userRoles, $permissionMap[$permission]));
    }

    /**
     * Check if user has general event management access
     */
    protected function hasEventManagementAccess($user): bool
    {
        $userRoles = $this->getUserRoles($user);
        $allowedRoles = ['admin', 'event_manager', 'staff', 'customer'];
        
        return !empty(array_intersect($userRoles, $allowedRoles));
    }

    /**
     * Get user roles
     */
    protected function getUserRoles($user): array
    {
        try {
            // Method 1: Check if user has roles relationship
            if (method_exists($user, 'roles') && $user->roles) {
                return $user->roles->pluck('name')->toArray();
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