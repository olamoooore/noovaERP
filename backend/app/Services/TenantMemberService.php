<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Single path that creates/attaches a workspace member.
 *
 * Used by:
 *  - TenantUserController::invite (platform superadmin only — tenant-side
 *    user creation is restricted, owners raise a request instead), and
 *  - AdminUserRequestController::approve (SuperAdmin console approving a
 *    tenant's request).
 *
 * Keeping one implementation means the security invariants established
 * for the invite flow (never grant privileged `users.role` values, central
 * user + `user_company_access` consistency, token revocation on password
 * set, mail-outage tolerance) hold for every creation path.
 */
class TenantMemberService
{
    /**
     * @return array{success: bool, code?: string, message: string, mode?: string, user_id?: int, name?: string, email?: string, role?: string, password_set?: bool|null, email_sent?: bool|null}
     */
    public function createOrAttachUser(string $tenantId, string $email, string $role, ?string $name = null, ?string $password = null): array
    {
        $email = strtolower(trim($email));
        $name = ($name !== null && trim($name) !== '') ? trim($name) : explode('@', $email)[0];

        // Look up existing user in public schema
        $existingUser = DB::table('public.users')->where('email', $email)->first();

        if ($existingUser) {
            // Check if user already has access to this tenant
            $alreadyHasAccess = DB::table('public.user_company_access')
                ->where('user_id', $existingUser->id)
                ->where('tenant_id', $tenantId)
                ->exists();

            if ($alreadyHasAccess) {
                return [
                    'success' => false,
                    'code' => 'already_member',
                    'message' => 'User is already a member of this workspace.',
                    'user_id' => (int) $existingUser->id,
                ];
            }

            DB::table('public.user_company_access')->insert([
                'user_id' => $existingUser->id,
                'tenant_id' => $tenantId,
                'role' => $role,
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $passwordSet = false;
            $emailSent = null;

            if ($password !== null) {
                DB::table('public.users')->where('id', $existingUser->id)->update([
                    'password' => Hash::make($password),
                    'updated_at' => now(),
                ]);
                $this->revokeUserTokens((int) $existingUser->id);
                $passwordSet = true;
            } else {
                // Existing account, newly granted workspace access: send a
                // set-password link so they are not left guessing credentials.
                $emailSent = $this->sendSetPasswordLink($existingUser->email, $existingUser->name, 'invite', $tenantId);
            }

            return [
                'success' => true,
                'mode' => 'attached',
                'user_id' => (int) $existingUser->id,
                'name' => $existingUser->name,
                'email' => $existingUser->email,
                'role' => $role,
                'password_set' => $passwordSet,
                'email_sent' => $emailSent,
                'message' => $passwordSet
                    ? 'User added to workspace with the password you set.'
                    : ($emailSent
                        ? 'User added to workspace, and a set-password link was emailed.'
                        : 'User added to the workspace, but the set-password email could not be sent. Use "Send password reset link" to retry.'),
            ];
        }

        // Create new central user.
        //
        // Pillar 2 Part 1 §3.4 / Pillar 1 P0-0: the `role` column is
        // deliberately never set here — a privileged default would grant a
        // global SuperAdmin bypass. Use `User::assignAdminRole()` if a
        // privileged role is genuinely required.
        $tempPassword = $password ?? Str::random(16);
        $newUserId = DB::table('public.users')->insertGetId([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => null,
            'password' => Hash::make($tempPassword),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('public.user_company_access')->insert([
            'user_id' => $newUserId,
            'tenant_id' => $tenantId,
            'role' => $role,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($password !== null) {
            return [
                'success' => true,
                'mode' => 'created',
                'user_id' => (int) $newUserId,
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'password_set' => true,
                'email_sent' => null,
                'message' => 'Member created with the password you set.',
            ];
        }

        $emailSent = $this->sendSetPasswordLink($email, $name, 'invite', $tenantId);

        return [
            'success' => true,
            'mode' => 'created',
            'user_id' => (int) $newUserId,
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'password_set' => false,
            'email_sent' => $emailSent,
            'message' => $emailSent
                ? 'Invitation sent. User created and added to workspace.'
                : 'User added to the workspace, but the invitation email could not be sent. Retry with "Send password reset link".',
        ];
    }

    /**
     * Deliver a set-password link by email. Returns false (never throws) so
     * a mail outage cannot roll back a membership change.
     */
    public function sendSetPasswordLink(string $email, ?string $name, string $variant, ?string $tenantId): bool
    {
        try {
            $linkService = app(\App\Services\PasswordLinkService::class);
            $token = $linkService->issue($email);
            $linkService->send($email, $name, $token, $variant, tenant('name'));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Tenant member password link failed to send', [
                'email' => $email,
                'tenant_id' => $tenantId,
                'variant' => $variant,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Drop the member's Sanctum tokens so a password change ends old sessions.
     */
    public function revokeUserTokens(int $userId): void
    {
        try {
            DB::table('public.personal_access_tokens')
                ->where('tokenable_id', $userId)
                ->delete();
        } catch (\Throwable $e) {
            Log::debug('Token revoke after password change failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
