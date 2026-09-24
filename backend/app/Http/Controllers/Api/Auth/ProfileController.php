<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Own-profile management endpoints.
 *
 * All endpoints here are scoped to the authenticated user — there is
 * no path that accepts a user id and acts on someone else. Tenant
 * super-roles are intentionally not bypassed (this is the user's own
 * account, not an admin action).
 *
 * Schema coverage:
 *   - name + email + phone + avatar are written to BOTH the central
 *     `public.users` row AND the active tenant's `users` row (when a
 *     tenant context is initialised). The two-schema sync is the same
 *     direction `SyncTenantUserFromCentralAuth` does on every request.
 *   - password is written only to the central row — `SyncTenantUser
 *     FromCentralAuth` will mirror it to the tenant row on the next
 *     request automatically.
 */
class ProfileController extends Controller
{
    /**
     * GET /api/v1/auth/me — already implemented in AuthController, but
     * we re-expose a /profile equivalent that always returns a merged
     * shape the frontend Profile page can consume directly.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->hydrateFromTenant($user);

        return response()->json([
            'user' => $user,
            'avatar_url' => $user->avatar_url,
        ]);
    }

    /**
     * PUT /api/v1/auth/me — update the authenticated user's profile
     * fields. Accepts: name, email, phone (all optional but at least
     * one must be present to avoid a no-op write).
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        if (empty($data)) {
            throw ValidationException::withMessages([
                'general' => ['At least one of name, email or phone must be provided.'],
            ]);
        }

        DB::transaction(function () use ($user, $data) {
            // 1. Central row — the canonical identity record.
            $user->fill($data);
            $user->save();

            // 2. Mirror to the active tenant's users row (if any).
            //    Skip silently when no tenant context is initialised
            //    (e.g. platform_super_admin browsing the admin console
            //    without selecting a tenant).
            if (function_exists('tenant') && tenant()) {
                $this->writeToTenant($user, $data);
            }
        });

        Log::info('profile.updated', [
            'user_id' => $user->id,
            'fields' => array_keys($data),
            'tenant_id' => function_exists('tenant') && tenant() ? tenant('id') : null,
            'ip' => $request->ip(),
        ]);

        $this->hydrateFromTenant($user);

        return response()->json([
            'user' => $user->fresh(),
            'avatar_url' => $user->avatar_url,
        ]);
    }

    /**
     * PUT /api/v1/auth/me/password — change the authenticated user's
     * password. Requires the current password to be supplied and
     * matched. Writes the new password to the central row only —
     * SyncTenantUserFromCentralAuth mirrors it on the next request.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            // Constant-ish response, no enumeration leak.
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->password = $data['password'];
        $user->save();

        // Invalidate every other Sanctum token so a stolen session
        // can't outlive the password change. The token used for the
        // password-change request itself is kept (the user is mid-flow).
        $currentTokenId = optional($user->currentAccessToken())->id;
        $user->tokens()
            ->where('id', '!=', $currentTokenId ?? 0)
            ->delete();

        Log::warning('profile.password_changed', [
            'user_id' => $user->id,
            'revoked_tokens' => $user->tokens()->count(),
            'ip' => $request->ip(),
        ]);

        return response()->json(['message' => 'Password updated successfully.']);
    }

    /**
     * POST /api/v1/auth/me/avatar — upload an avatar image. Accepts
     * multipart/form-data with `avatar` (jpeg/png/jpg/gif/webp, max
     * 10 MB). Replaces the previous avatar file on disk if any.
     */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'avatar' => ['required', 'file', 'mimes:jpeg,png,jpg,gif,webp', 'max:10240'],
        ]);

        $file = $request->file('avatar');
        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $filename = Str::random(40).'.'.$extension;
        $path = $file->storeAs('avatars', $filename, 'public');

        $previousPath = $user->avatar;
        if ($previousPath && ! preg_match('#^https?://#i', $previousPath) && Storage::disk('public')->exists($previousPath)) {
            Storage::disk('public')->delete($previousPath);
        }

        $user->avatar = $path;
        $user->save();

        if (function_exists('tenant') && tenant()) {
            $this->writeToTenant($user, ['avatar' => $path]);
        }

        Log::info('profile.avatar_uploaded', [
            'user_id' => $user->id,
            'path' => $path,
            'previous_path' => $previousPath,
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'avatar_url' => $user->fresh()->avatar_url,
        ]);
    }

    /**
     * DELETE /api/v1/auth/me/avatar — remove the avatar. Deletes the
     * file from disk (when locally stored) and clears the column on
     * both schemas.
     */
    public function destroyAvatar(Request $request): JsonResponse
    {
        $user = $request->user();

        $previousPath = $user->avatar;
        if ($previousPath && ! preg_match('#^https?://#i', $previousPath) && Storage::disk('public')->exists($previousPath)) {
            Storage::disk('public')->delete($previousPath);
        }

        $user->avatar = null;
        $user->save();

        if (function_exists('tenant') && tenant()) {
            $this->writeToTenant($user, ['avatar' => null]);
        }

        Log::info('profile.avatar_removed', [
            'user_id' => $user->id,
            'previous_path' => $previousPath,
            'ip' => $request->ip(),
        ]);

        return response()->json(['message' => 'Avatar removed.']);
    }

    /**
     * Copy avatar + phone from the active tenant's users row onto the
     * central user instance so the response carries the merged shape.
     * No-op when there is no tenant context.
     */
    private function hydrateFromTenant(User $user): void
    {
        if (! function_exists('tenant') || ! tenant()) {
            return;
        }

        $tenantRow = DB::table('users')
            ->where('email', $user->email)
            ->first();

        if (! $tenantRow) {
            return;
        }

        // Phone is only authoritative on the tenant row currently
        // (the central `users` table only just gained the column).
        // Prefer central when set, fall back to tenant.
        if (empty($user->phone) && ! empty($tenantRow->phone)) {
            $user->phone = $tenantRow->phone;
        }

        // Avatar path: prefer central when set (the user's just-uploaded
        // avatar lives there first), fall back to tenant.
        if (empty($user->avatar) && ! empty($tenantRow->avatar)) {
            $user->avatar = $tenantRow->avatar;
        }
    }

    /**
     * Mirror the given attribute set onto the tenant-schema users
     * row, creating it if missing (so a profile update works even
     * before SyncTenantUserFromCentralAuth has had a chance to run).
     *
     * @param  array<string, mixed>  $data
     */
    private function writeToTenant(User $user, array $data): void
    {
        $tenantId = tenant('id');
        if (! $tenantId) {
            return;
        }

        $now = now();

        $existing = DB::table('users')->where('email', $user->email)->first();
        $payload = array_intersect_key($data, array_flip(['name', 'email', 'phone', 'avatar']));

        if ($existing) {
            if (! empty($payload)) {
                DB::table('users')
                    ->where('id', $existing->id)
                    ->update(array_merge($payload, ['updated_at' => $now]));
            }
        } else {
            DB::table('users')->insert(array_merge(
                [
                    'name' => $user->name,
                    'email' => $user->email,
                    'password' => $user->password,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                $payload
            ));
        }
    }
}
