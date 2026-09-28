<?php

namespace App\Services;

use App\Mail\PasswordLinkMail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Issues and consumes "set your password" links for central (public-schema)
 * users — used both by forgot-password and by tenant invites, so a newly
 * invited member can actually sign in (previously the random temporary
 * password was never delivered anywhere).
 *
 * Deliberately operates on public.* tables only: it runs inside tenant
 * requests (invites) and central requests (reset), where the active
 * search_path differs.
 */
class PasswordLinkService
{
    /** Links stay valid for a week — long enough for invites to be picked up. */
    public const TTL_DAYS = 7;

    /**
     * Create (or replace) a password link token for the email.
     *
     * @return string the plaintext token to put into the emailed URL
     */
    public function issue(string $email): string
    {
        $plain = Str::random(64);

        DB::table('public.password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => Hash::make($plain), 'created_at' => now()],
        );

        return $plain;
    }

    /**
     * Build the SPA link, mail it, and return the URL (handy for tests/logs).
     */
    public function send(string $email, ?string $name, string $token, string $variant, ?string $tenantName = null): string
    {
        $base = rtrim((string) (env('FRONTEND_URL') ?: config('app.url', '')), '/');
        $url = $base.'/reset-password?token='.$token.'&email='.urlencode($email);

        Mail::to($email)->send(new PasswordLinkMail($url, $variant, $name, $tenantName));

        return $url;
    }

    /**
     * Validate the token and set the new password on the central user.
     * Returns false when the token is unknown, mismatched, or expired.
     */
    public function consume(string $email, string $token, string $password): bool
    {
        $row = DB::table('public.password_reset_tokens')->where('email', $email)->first();

        if (! $row || ! Hash::check($token, (string) $row->token)) {
            return false;
        }

        if (Carbon::parse($row->created_at)->lt(now()->subDays(self::TTL_DAYS))) {
            return false;
        }

        $user = DB::table('public.users')->where('email', $email)->first();

        if (! $user) {
            return false;
        }

        DB::table('public.users')->where('id', $user->id)->update([
            'password' => Hash::make($password),
            'email_verified_at' => $user->email_verified_at ?: now(),
            'updated_at' => now(),
        ]);

        DB::table('public.password_reset_tokens')->where('email', $email)->delete();

        // A password change invalidates every existing API session.
        DB::table('public.personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->delete();

        return true;
    }
}
