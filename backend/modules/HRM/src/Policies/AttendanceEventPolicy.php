<?php

namespace Modules\HRM\Policies;

use App\Models\User;
use Modules\HRM\Models\AttendanceEvent;

class AttendanceEventPolicy
{
    public function viewAny(User $user): bool
    {
        // TODO: restrict to HR role
        return auth()->check();
    }

    public function view(User $user, AttendanceEvent $event): bool
    {
        // TODO: restrict to HR role
        return auth()->check();
    }

    public function create(User $user): bool
    {
        // TODO: restrict to HR role
        return auth()->check();
    }

    public function delete(User $user, AttendanceEvent $event): bool
    {
        // TODO: restrict to HR role
        return auth()->check();
    }
}
