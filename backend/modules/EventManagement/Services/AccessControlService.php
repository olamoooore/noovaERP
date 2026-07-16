<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\Auth;

class AccessControlService
{
    /**
     * Check if a user can view inventory data
     *
     * @param  mixed  $user
     */
    public function canViewInventory($user): bool
    {
        if (! $user) {
            return false;
        }

        return $this->isStaff($user);
    }

    /**
     * Check if a user is staff
     *
     * @param  mixed  $user
     */
    public function isStaff($user): bool
    {
        if (! $user) {
            return false;
        }

        // Check if user has staff, admin, or super-admin role
        return $user->hasAnyRole(['staff', 'admin', 'super-admin']);
    }

    /**
     * Check if a user can configure events
     *
     * @param  mixed  $user
     */
    public function canConfigureEvents($user): bool
    {
        if (! $user) {
            return false;
        }

        // Only admin and super-admin can configure events
        return $user->hasAnyRole(['admin', 'super-admin']);
    }

    /**
     * Get the current authenticated user
     *
     * @return mixed
     */
    public function getCurrentUser()
    {
        return Auth::user();
    }

    /**
     * Check if current user can view inventory
     */
    public function currentUserCanViewInventory(): bool
    {
        return $this->canViewInventory($this->getCurrentUser());
    }

    /**
     * Check if current user is staff
     */
    public function currentUserIsStaff(): bool
    {
        return $this->isStaff($this->getCurrentUser());
    }

    /**
     * Check if current user can configure events
     */
    public function currentUserCanConfigureEvents(): bool
    {
        return $this->canConfigureEvents($this->getCurrentUser());
    }

    /**
     * Log inventory access by staff
     *
     * @param  mixed  $user
     */
    public function logInventoryAccess(string $eventId, $user, string $action = 'view_inventory'): void
    {
        if (! $user) {
            return;
        }

        activity()
            ->causedBy($user)
            ->withProperties([
                'event_id' => $eventId,
                'action' => $action,
                'user_id' => $user->id,
                'timestamp' => now()->toIso8601String(),
            ])
            ->log('Inventory access');
    }

    /**
     * Log inventory access for current user
     */
    public function logCurrentUserInventoryAccess(string $eventId, string $action = 'view_inventory'): void
    {
        $user = $this->getCurrentUser();

        if ($user && $this->isStaff($user)) {
            $this->logInventoryAccess($eventId, $user, $action);
        }
    }
}
