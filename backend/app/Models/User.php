<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Issue #P2-1 — Pillar 2 Part 1 §3.4 / Pillar 1 P0-0:
 *
 * The `role` column on the central `users` table holds a free-text
 * platform role string (e.g. `platform_super_admin`) that is read as
 * the global SuperAdmin bypass by 8+ locations. Per the report, the
 * column is removed from `$fillable` AND a model event blocks any
 * non-`assignAdminRole` path that would set a privileged value.
 *
 * Per the report's Pillar 1 P0-0 fix #1: "either omit the column
 * entirely and change the schema default to something unprivileged,
 * or explicitly set it to a fixed, non-admin value on every insert
 * path into public.users that isn't the dedicated admin-provisioning
 * flow." Plus fix #3: "consider a dedicated, harder-to-accidentally-
 * grant admin-role mechanism". Both:
 *   (a) the column is removed from $fillable,
 *   (b) a `saving` event below strips `role` unless the caller already
 *       ran `assignAdminRole()` in the same instance lifecycle,
 *   (c) the schema default is NULL (per migration 2026_08_26_000001).
 *
 * Existing rows are NOT mutated — any pre-existing real SuperAdmin
 * keeps the value, and any pre-existing compromised value persists
 * until cleaned up via AdminUserService. The new schema default
 * prevents future insertions from silently granting it.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Fields that are mass-assignable via User::create($attrs) or
     * $user->fill($attrs).
     *
     * Notably EXCLUDED:
     *   - `role` — the platform-SuperAdmin role. Must be set via the
     *     dedicated `assignAdminRole()` method below; otherwise anyone
     *     who can mass-assign can self-grant the most powerful bypass
     *     in the system. See `docs/core-platform-pillar2-review-2026-08-22/part1_isolation_sod_override.md` §3.4.
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'avatar',
        'password',
        'is_active',
        'deactivated_at',
        'is_system',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'meta' => 'array',
        'password' => 'hashed',
    ];

    /**
     * The set of `role` values that grant the global SuperAdmin bypass.
     * Mirrors `RequirePermission::handle` lines 39-65 (and the
     * `EnsureAdminRole::ADMIN_ROLES` whitelist, the `AppServiceProvider`
     * Gate definitions, `AdminAuthController::login`, etc.).
     *
     * Centralised here so any future change to the privileged set is a
     * single edit rather than a multi-file find/replace — per the
     * Pillar 1 remediation instructions §4.1 ("fix every other code
     * path that inserts into public.users for the same default-value
     * trap").
     */
    public const PRIVILEGED_ROLES = [
        'platform_super_admin',
    ];

    /**
     * Per-instance flag set by `assignAdminRole()` so the `saving`
     * event below knows to permit a privileged value rather than
     * silently stripping it. Laravel factories bypass $fillable and
     * can therefore set $model->role directly; this flag is the
     * second layer that catches that case.
     */
    private bool $allowPrivilegedRoleSave = false;

    protected static function booted(): void
    {
        static::saving(function (self $user) {
            // If the saving instance has a role set to a privileged
            // value but the dedicated assignAdminRole() method was
            // not the source, strip the role. This catches (a)
            // direct property writes (factories), (b) Eloquent mass-
            // assignment via make()/create() that bypass $fillable
            // (factories), and (c) any future controller that forgets
            // to use the restricted method.
            $role = $user->getAttribute('role');
            if ($role !== null
                && in_array($role, self::PRIVILEGED_ROLES, true)
                && ! $user->allowPrivilegedRoleSave) {
                Log::warning('users.role blocked by saving guard', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'attempted_role' => $role,
                    'source' => 'non-assignAdminRole path',
                ]);
                $user->setAttribute('role', null);
            }
        });
    }

    public function isSuperAdmin(): bool
    {
        return (bool) ($this->is_super_admin ?? false)
            || in_array($this->role, self::PRIVILEGED_ROLES, true);
    }

    /**
     * Absolute URL to the user's avatar image.
     *
     * Returns null when no avatar is set so the frontend can fall back
     * to initials. `Storage::url()` resolves to `${APP_URL}/storage/...`
     * for the public disk — matches the `UploadController@store` shape
     * used everywhere else.
     */
    public function getAvatarUrlAttribute(): ?string
    {
        $path = $this->avatar;
        if (! $path) {
            return null;
        }

        // If the stored value is already an absolute URL (e.g. pasted
        // by hand or returned from an external identity provider),
        // return it as-is.
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * Assign a privileged platform role to this user.
     *
     * This is the ONLY path by which `users.role` may be set to a
     * value in `PRIVILEGED_ROLES`. Every caller is required to log
     * who issued the call and why.
     *
     * Per Pillar 1 P0-0 fix #3: "consider a dedicated, harder-to-
     * accidentally-grant admin-role mechanism". This method is
     * step 1 — every consumer must explicitly call it instead of
     * setting `role` via mass-assignment.
     *
     * @param  string  $adminRole  Must be in `PRIVILEGED_ROLES`. Pass
     *                             `null` to clear the role.
     * @param  int|string|null  $actorId  The authenticated actor
     *                                    performing the assignment. Required
     *                                    for audit; pass `null` only from seeders
     *                                    or system-initiated flows.
     * @param  string|null  $reason  Free-text audit justification.
     *
     * @throws \InvalidArgumentException if `$adminRole` is not in `PRIVILEGED_ROLES` and is non-null.
     */
    public function assignAdminRole(string $adminRole, int|string|null $actorId = null, ?string $reason = null): void
    {
        if ($adminRole !== null && ! in_array($adminRole, self::PRIVILEGED_ROLES, true)) {
            throw new \InvalidArgumentException(
                "Cannot assign non-privileged role '{$adminRole}' via assignAdminRole(); ".
                'use $user->role = $value on a fresh query for a normal user attribute.'
            );
        }

        $previous = $this->role;
        $this->role = $adminRole;

        // Tell the saving event to permit this privileged value.
        $this->allowPrivilegedRoleSave = true;
        try {
            $this->save();
        } finally {
            $this->allowPrivilegedRoleSave = false;
        }

        Log::warning('users.role assigned', [
            'user_id' => $this->id,
            'email' => $this->email,
            'previous_role' => $previous,
            'new_role' => $adminRole,
            'actor_id' => $actorId,
            'reason' => $reason,
            'ip' => request()?->ip(),
        ]);
    }
}
