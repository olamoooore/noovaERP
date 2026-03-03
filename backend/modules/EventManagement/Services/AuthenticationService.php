<?php

namespace Modules\EventManagement\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Facades\LogActivity;

/**
 * Consolidated Authentication Service for EventManagement Module
 * 
 * This service isolates all authentication-related logic for the EventManagement module,
 * providing a clear boundary around auth operations while preserving existing behavior.
 * 
 * Requirements: 6.1 - Isolate authentication flows in EventManagement
 */
class AuthenticationService
{
    /**
     * Authenticate user with email and password
     *
     * @param string $email
     * @param string $password
     * @return array
     * @throws ValidationException
     */
    public function authenticate(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$user->is_active) {
            throw ValidationException::withMessages([
                'account' => ['Account is inactive'],
            ]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        // Log authentication event
        $this->logAuthenticationEvent($user, 'login', [
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return [
            'user' => $user->load('roles.permissions'),
            'token' => $token,
        ];
    }

    /**
     * Logout user by revoking current access token
     *
     * @param Request $request
     * @return void
     */
    public function logout(Request $request): void
    {
        $user = $request->user();
        
        if ($user) {
            // Log logout event
            $this->logAuthenticationEvent($user, 'logout', [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // Revoke current access token
            $request->user()->currentAccessToken()->delete();
        }
    }

    /**
     * Get authenticated user with roles and permissions
     *
     * @param Request $request
     * @return User|null
     */
    public function getAuthenticatedUser(Request $request): ?User
    {
        $user = $request->user();
        
        if ($user) {
            return $user->load('roles.permissions');
        }

        return null;
    }

    /**
     * Check if user has specific permission
     *
     * @param User|null $user
     * @param string $permission
     * @return bool
     */
    public function hasPermission(?User $user, string $permission): bool
    {
        if (!$user) {
            return false;
        }

        return $user->can($permission);
    }

    /**
     * Check if user has any of the specified roles
     *
     * @param User|null $user
     * @param array $roles
     * @return bool
     */
    public function hasAnyRole(?User $user, array $roles): bool
    {
        if (!$user) {
            return false;
        }

        return $user->hasAnyRole($roles);
    }

    /**
     * Check if user has specific role
     *
     * @param User|null $user
     * @param string $role
     * @return bool
     */
    public function hasRole(?User $user, string $role): bool
    {
        if (!$user) {
            return false;
        }

        return $user->hasRole($role);
    }

    /**
     * Get user's roles
     *
     * @param User|null $user
     * @return array
     */
    public function getUserRoles(?User $user): array
    {
        if (!$user) {
            return [];
        }

        return $user->roles->pluck('name')->toArray();
    }

    /**
     * Get user's permissions
     *
     * @param User|null $user
     * @return array
     */
    public function getUserPermissions(?User $user): array
    {
        if (!$user) {
            return [];
        }

        return $user->getAllPermissions()->pluck('name')->toArray();
    }

    /**
     * Check if user can access EventManagement module
     *
     * @param User|null $user
     * @return bool
     */
    public function canAccessEventManagement(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        // Check if user has any EventManagement-related permissions
        $eventPermissions = [
            'events.view',
            'events.create',
            'events.update',
            'events.delete',
            'bookings.view',
            'bookings.create',
            'bookings.manage',
            'tickets.view',
            'tickets.validate',
            'operations.view',
            'operations.checkin',
        ];

        foreach ($eventPermissions as $permission) {
            if ($this->hasPermission($user, $permission)) {
                return true;
            }
        }

        // Also check for staff, admin, or super-admin roles
        return $this->hasAnyRole($user, ['staff', 'admin', 'super-admin']);
    }

    /**
     * Check if user is staff member
     *
     * @param User|null $user
     * @return bool
     */
    public function isStaff(?User $user): bool
    {
        return $this->hasAnyRole($user, ['staff', 'admin', 'super-admin']);
    }

    /**
     * Check if user is admin
     *
     * @param User|null $user
     * @return bool
     */
    public function isAdmin(?User $user): bool
    {
        return $this->hasAnyRole($user, ['admin', 'super-admin']);
    }

    /**
     * Check if user can configure events
     *
     * @param User|null $user
     * @return bool
     */
    public function canConfigureEvents(?User $user): bool
    {
        return $this->hasPermission($user, 'events.configure') || 
               $this->hasAnyRole($user, ['admin', 'super-admin']);
    }

    /**
     * Check if user can view inventory
     *
     * @param User|null $user
     * @return bool
     */
    public function canViewInventory(?User $user): bool
    {
        return $this->hasPermission($user, 'inventory.view') || 
               $this->isStaff($user);
    }

    /**
     * Get feature flags for user based on their roles
     *
     * @param User|null $user
     * @param string $appType
     * @return array
     */
    public function getFeatureFlagsForUser(?User $user, string $appType = 'customer'): array
    {
        $baseFeatures = [
            'core_events' => true,
            'basic_booking' => true,
        ];

        if (!$user) {
            return $baseFeatures;
        }

        if ($this->isAdmin($user) || $appType === 'admin') {
            return array_merge($baseFeatures, [
                'analytics_dashboard' => true,
                'artist_management' => true,
                'fraud_detection' => true,
                'operations_console' => true,
                'pii_governance' => true,
                'advanced_reporting' => true,
            ]);
        }

        if ($this->isStaff($user) || $appType === 'staff') {
            return array_merge($baseFeatures, [
                'check_in_management' => true,
                'incident_reporting' => true,
                'real_time_monitoring' => true,
                'staff_assignments' => true,
            ]);
        }

        // Customer features
        return array_merge($baseFeatures, [
            'loyalty_program' => true,
            'artist_browsing' => true,
            'ticket_management' => true,
            'early_access' => true,
        ]);
    }

    /**
     * Log authentication-related events
     *
     * @param User $user
     * @param string $action
     * @param array $properties
     * @return void
     */
    private function logAuthenticationEvent(User $user, string $action, array $properties = []): void
    {
        activity()
            ->causedBy($user)
            ->withProperties(array_merge([
                'action' => $action,
                'module' => 'EventManagement',
                'timestamp' => now()->toIso8601String(),
            ], $properties))
            ->log("Authentication: {$action}");
    }

    /**
     * Validate token and return user
     *
     * @param string $token
     * @return User|null
     */
    public function validateToken(string $token): ?User
    {
        try {
            // This would typically involve JWT validation or database lookup
            // For Laravel Sanctum, the middleware handles this automatically
            return Auth::user();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if current request is authenticated
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        return Auth::check();
    }

    /**
     * Get current authenticated user
     *
     * @return User|null
     */
    public function getCurrentUser(): ?User
    {
        return Auth::user();
    }
}