<?php

namespace Modules\HRM\Policies;

use App\Models\User;
use Modules\HRM\Models\EmployeeUserLink;

class EmployeeUserLinkPolicy
{
    public function viewAny(User $user): bool
    {
        // TODO: restrict to HR role
        return auth()->check();
    }

    public function view(User $user, EmployeeUserLink $link): bool
    {
        // TODO: restrict to HR role
        return auth()->check();
    }

    public function create(User $user): bool
    {
        // TODO: restrict to HR role
        return auth()->check();
    }

    public function delete(User $user, EmployeeUserLink $link): bool
    {
        // TODO: restrict to HR role
        return auth()->check();
    }
}
