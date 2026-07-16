<?php

declare(strict_types=1);

namespace App\SuperAdmin\Application;

use App\Models\User;
use App\SuperAdmin\Domain\AdminInvitation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminUserService
{
    public const ROLES = [
        'platform_super_admin',
        'platform_ops',
        'sales_ops',
        'billing_ops',
        'support_readonly',
    ];

    private const INVITATION_EXPIRY_DAYS = 7;

    public function __construct(
        private readonly AdminAuditLogger $auditLogger,
    ) {}

    /**
     * Create an invitation record and return the accept link.
     *
     * Security: the raw token is returned to the caller (for the URL) but only
     * a SHA-256 hash is stored in the database. If the database is compromised,
     * stored hashes cannot be replayed.
     *
     * Idempotency: any previous pending invite for the same email is cancelled first.
     */
    public function invite(string $email, string $role): array
    {
        if (! in_array($role, self::ROLES, true)) {
            throw ValidationException::withMessages([
                'role' => 'Invalid role.',
            ]);
        }

        // Prevent re-inviting a user who already has an admin account
        if (User::where('email', $email)->whereNotNull('role')->exists()) {
            throw ValidationException::withMessages([
                'email' => 'A user with this email already has an admin account.',
            ]);
        }

        // Idempotency: cancel any pending invite for this address before issuing a new one
        AdminInvitation::where('email', $email)
            ->whereNull('accepted_at')
            ->delete();

        // Generate a cryptographically secure raw token (stays in the URL)
        $rawToken = Str::random(64);
        // Store only the hash — prevents DB-dump exploitation
        $hashedToken = hash('sha256', $rawToken);

        $invitation = AdminInvitation::create([
            'email' => $email,
            'role' => $role,
            'token' => $hashedToken,  // hashed value stored
            'invited_by_admin_user_id' => Auth::id(),
            'expires_at' => now()->addDays(self::INVITATION_EXPIRY_DAYS),
            'created_at' => now(),
        ]);

        $this->auditLogger->log(
            action: 'admin.user.invited',
            target: null,
            before: [],
            after: ['email' => $email, 'role' => $role],
            status: 'success',
        );

        $acceptUrl = config('app.superadmin_url', 'http://localhost:5174')
            .'/accept-invite?token='.$rawToken;  // raw token in URL

        return [
            'invitation' => $invitation,
            'accept_url' => $acceptUrl,
        ];
    }

    /**
     * Accept an invitation: hash the incoming token and look up by hash.
     *
     * Idempotency: the accepted_at column prevents the same token being used twice.
     */
    public function acceptInvite(string $rawToken, string $password): User
    {
        $hashedToken = hash('sha256', $rawToken);

        $invitation = AdminInvitation::where('token', $hashedToken)
            ->whereNull('accepted_at')
            ->first();

        if (! $invitation) {
            throw ValidationException::withMessages([
                'token' => 'Invalid or already used invitation token.',
            ]);
        }

        if ($invitation->isExpired()) {
            throw ValidationException::withMessages([
                'token' => 'This invitation has expired.',
            ]);
        }

        return DB::transaction(function () use ($invitation, $password) {
            $user = User::create([
                'name' => explode('@', $invitation->email)[0],
                'email' => $invitation->email,
                'password' => Hash::make($password),
                'role' => $invitation->role,
            ]);

            // Mark accepted — prevents replay (idempotency at token level)
            $invitation->update(['accepted_at' => now()]);

            $this->auditLogger->log(
                action: 'admin.user.created',
                target: $user,
                before: [],
                after: ['email' => $user->email, 'role' => $user->role],
                status: 'success',
            );

            return $user;
        });
    }

    public function listAdmins(): Collection
    {
        return User::whereNotNull('role')
            ->orderBy('created_at')
            ->get(['id', 'name', 'email', 'role', 'is_active', 'deactivated_at', 'created_at']);
    }

    public function listPendingInvitations(): Collection
    {
        return AdminInvitation::whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->with('invitedBy:id,name,email')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Update the role of an existing admin user.
     *
     * Idempotency guard: if the role is already the target role, this is a no-op.
     */
    public function updateRole(User $admin, string $newRole): User
    {
        if (! in_array($newRole, self::ROLES, true)) {
            throw ValidationException::withMessages([
                'role' => 'Invalid role.',
            ]);
        }

        if ($admin->id === Auth::id() && $admin->role === 'platform_super_admin') {
            throw ValidationException::withMessages([
                'role' => 'You cannot change your own role.',
            ]);
        }

        // Prevent demoting the last platform_super_admin — would lock everyone out
        if ($admin->role === 'platform_super_admin' && $newRole !== 'platform_super_admin') {
            $superAdminCount = User::where('role', 'platform_super_admin')->count();
            if ($superAdminCount <= 1) {
                throw ValidationException::withMessages([
                    'role' => 'Cannot demote the last platform_super_admin.',
                ]);
            }
        }

        // Idempotency: already at target role — return early without duplicate audit log
        if ($admin->role === $newRole) {
            return $admin;
        }

        $before = ['role' => $admin->role];
        $admin->update(['role' => $newRole]);

        $this->auditLogger->log(
            action: 'admin.user.role_changed',
            target: $admin,
            before: $before,
            after: ['role' => $newRole],
            status: 'success',
        );

        return $admin->fresh();
    }

    public function deactivate(User $admin): void
    {
        if ($admin->id === Auth::id()) {
            throw ValidationException::withMessages([
                'id' => 'You cannot deactivate your own account.',
            ]);
        }

        // Idempotency: already deactivated
        if (! $admin->is_active) {
            return;
        }

        $before = ['role' => $admin->role, 'email' => $admin->email, 'is_active' => true];
        $admin->update([
            'is_active' => false,
            'deactivated_at' => now(),
        ]);

        $this->auditLogger->log(
            action: 'admin.user.deactivated',
            target: $admin,
            before: $before,
            after: ['is_active' => false, 'deactivated_at' => now()->toIso8601String()],
            status: 'success',
        );
    }

    /**
     * Reactivate a previously deactivated admin user.
     */
    public function reactivate(User $admin): User
    {
        if ($admin->is_active) {
            return $admin; // Idempotency: already active
        }

        $before = ['is_active' => false];
        $admin->update([
            'is_active' => true,
            'deactivated_at' => null,
        ]);

        $this->auditLogger->log(
            action: 'admin.user.reactivated',
            target: $admin,
            before: $before,
            after: ['is_active' => true],
            status: 'success',
        );

        return $admin->fresh();
    }
}
