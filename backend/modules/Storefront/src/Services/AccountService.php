<?php

namespace Modules\Storefront\Services;

use App\Services\CanonicalCustomerService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Communication\Services\CommunicationEngine;
use Modules\Storefront\Exceptions\AccountAuthException;
use Modules\Storefront\Models\StorefrontAccount;
use Modules\Storefront\Models\StorefrontAccountToken;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontSession;
use Modules\Storefront\Models\StorefrontSettings;

/**
 * Account identity service (decision D2: auth lives in storefront_accounts,
 * linked 1:1 to CRM customers; the CRM record itself is created through
 * CanonicalCustomerService so contact-master sync stays platform-owned).
 *
 * Security posture implemented here (FR-ID-*):
 *  - passwords: platform hasher via the model's `hashed` cast (FR-ID-03);
 *  - sessions: random 32-byte tokens, SHA-256 at rest, rotation on refresh,
 *    revocation on logout / password reset (FR-ID-04/06, D3);
 *  - lockout after repeated failures, GENERIC errors — no account
 *    enumeration (FR-ID-05);
 *  - verification/reset tokens: hashed, single-use, time-boxed (FR-ID-02/06);
 *  - email delivery ONLY through the Communication engine (non-negotiable #4);
 *  - status/verification re-checked wherever a session is resolved by
 *    StorefrontAccountAuth on every request (FR-ID-08);
 *  - guest orders linked to the account on login (FR-ID-07).
 */
final class AccountService
{
    public const SESSION_COOKIE = 'sf_session';

    public const CSRF_COOKIE = 'sf_csrf';

    public const SESSION_TTL_DAYS = 30;

    public const MAX_FAILED_LOGINS = 5;

    public const LOCK_MINUTES = 15;

    public const VERIFY_TTL_HOURS = 24;

    public const RESET_TTL_MINUTES = 60;

    /** Runtime-computed dummy hash so wrong-password checks on MISSING
     *  accounts cost the same as on existing ones (timing side-channel). */
    private static ?string $timingSafeHash = null;

    public function __construct(
        private readonly CanonicalCustomerService $customers,
        private readonly CommunicationEngine $communication,
    ) {}

    // ─── Registration + verification ────────────────────────────────────

    /**
     * @return array{account: StorefrontAccount, verification_token: string}
     */
    public function register(
        string $email,
        string $password,
        ?string $firstName = null,
        ?string $lastName = null,
        ?string $phone = null,
        array $consent = [],
        ?string $locale = null,
    ): array {
        $email = mb_strtolower(trim($email));

        if (StorefrontAccount::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw AccountAuthException::make('email_taken', 'An account already exists for this email.', 422);
        }

        // One identity: link/create the CRM customer through the canonical path.
        $customer = \App\Models\Customer::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->first();
        $customer ??= $this->customers->create([
            'name' => trim(($firstName ?? '').' '.($lastName ?? '')) ?: $email,
            'email' => $email,
            'phone' => $phone,
            'is_active' => true,
        ]);

        $account = StorefrontAccount::create([
            'customer_id' => $customer->id,
            'email' => $email,
            'password' => $password,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $phone,
            'status' => 'pending',
            'locale' => $locale,
            'currency_code' => StorefrontSettings::current()->currency_code,
            'marketing_email_opt_in' => (bool) ($consent['email'] ?? false),
            'marketing_sms_opt_in' => (bool) ($consent['sms'] ?? false),
            'marketing_whatsapp_opt_in' => (bool) ($consent['whatsapp'] ?? false),
            'consent_source' => $consent === [] ? null : 'storefront_register',
            'consent_at' => $consent === [] ? null : now(),
        ]);

        $verificationToken = $this->issueToken($account, StorefrontAccountToken::PURPOSE_VERIFICATION, self::VERIFY_TTL_HOURS * 3600);

        $this->sendEmail(
            to: $account->email,
            subject: 'Verify your account',
            body: 'Welcome! Please verify your account: '.$verificationToken,
            subtype: 'storefront.customer.email_verification',
            templateKey: 'auth.welcome',
            variables: ['user' => ['name' => $account->first_name ?: $account->email], 'verify' => ['url' => $verificationToken]],
        );

        return ['account' => $account, 'verification_token' => $verificationToken];
    }

    public function verifyEmail(string $email, string $token): StorefrontAccount
    {
        $account = StorefrontAccount::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])
            ->first();

        if ($account === null) {
            throw AccountAuthException::make('invalid_token', 'This verification link is not valid.', 422);
        }

        $this->consumeToken($account, $token, StorefrontAccountToken::PURPOSE_VERIFICATION);

        $account->update([
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->sendEmail(
            to: $account->email,
            subject: 'Welcome — your account is verified',
            body: 'Your account is now active. You can sign in any time.',
            subtype: 'storefront.customer.registered',
            templateKey: 'auth.welcome',
            variables: ['user' => ['name' => $account->first_name ?: $account->email]],
        );

        return $account;
    }

    // ─── Login / sessions ───────────────────────────────────────────────

    /**
     * @return array{account: StorefrontAccount, session: StorefrontSession, session_token: string, csrf_token: string}
     */
    public function login(string $email, string $password, ?string $ip = null, ?string $device = null): array
    {
        $account = StorefrontAccount::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])
            ->first();

        if ($account !== null && $account->locked_until !== null && $account->locked_until->isFuture()) {
            throw AccountAuthException::make('account_locked', 'Too many failed attempts. Try again later.', 429);
        }

        // Generic failure shape whether the account exists or not (FR-ID-05).
        if ($account === null || ! Hash::check($password, $account->password)) {
            if ($account !== null) {
                $failures = (int) $account->failed_login_count + 1;
                $account->update([
                    'failed_login_count' => $failures,
                    'locked_until' => $failures >= self::MAX_FAILED_LOGINS ? now()->addMinutes(self::LOCK_MINUTES) : null,
                ]);
            }

            // Constant-ish time regardless of account existence.
            self::$timingSafeHash ??= Hash::make('storefront-timing-equalizer-'.Str::random(16));
            Hash::check($password, self::$timingSafeHash);

            throw AccountAuthException::make('invalid_credentials', 'These credentials do not match our records.', 401);
        }

        if ($account->status === 'suspended') {
            throw AccountAuthException::make('account_suspended', 'This account has been suspended.', 403);
        }

        $account->update(['failed_login_count' => 0, 'locked_until' => null, 'last_login_at' => now()]);

        // FR-ID-07: link prior guest orders placed with this email.
        $this->linkGuestOrders($account);

        return $this->createSession($account, $ip, $device);
    }

    /**
     * @return array{account: StorefrontAccount, session: StorefrontSession, session_token: string, csrf_token: string}
     */
    public function createSession(StorefrontAccount $account, ?string $ip = null, ?string $device = null): array
    {
        $plain = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(16));

        $session = StorefrontSession::create([
            'storefront_account_id' => $account->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(self::SESSION_TTL_DAYS),
            'device' => $device ? mb_substr($device, 0, 160) : null,
            'ip' => $ip,
        ]);

        return [
            'account' => $account,
            'session' => $session,
            'session_token' => $plain,
            'csrf_token' => $csrf,
        ];
    }

    /**
     * Resolve a session token for the auth middleware (FR-ID-04/08).
     * Status + expiry re-verified on EVERY call — stale states cannot
     * survive on an existing cookie.
     */
    public function resolveSession(?string $plainToken): ?array
    {
        if ($plainToken === null || preg_match('/^[a-f0-9]{64}$/', $plainToken) !== 1) {
            return null;
        }

        $session = StorefrontSession::query()
            ->with('account')
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if ($session === null || $session->revoked_at !== null || $session->expires_at->isPast()) {
            return null;
        }

        $account = $session->account;

        if ($account === null || $account->status !== 'active' || $account->deleted_at !== null) {
            return null;
        }

        if ($session->last_used_at === null || $session->last_used_at->lt(now()->subMinutes(5))) {
            $session->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return ['account' => $account, 'session' => $session];
    }

    public function rotateSession(StorefrontSession $session, ?string $ip = null): array
    {
        $session->forceFill(['revoked_at' => now()])->save();

        return $this->createSession($session->account()->first(), $ip, $session->device);
    }

    public function logout(StorefrontSession $session): void
    {
        $session->forceFill(['revoked_at' => now()])->save();
    }

    public function revokeAllSessions(StorefrontAccount $account): int
    {
        return StorefrontSession::query()
            ->where('storefront_account_id', $account->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    // ─── Password recovery ──────────────────────────────────────────────

    /**
     * Always completes silently for unknown emails — no enumeration
     * (FR-ID-05/06). Returns the plain token ONLY when a reset mail was
     * dispatched (test seam + ops recovery); callers must not expose it in
     * any customer response.
     */
    public function sendPasswordReset(string $email): ?string
    {
        $account = StorefrontAccount::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])
            ->where('status', '!=', 'suspended')
            ->first();

        if ($account === null) {
            return null;
        }

        $token = $this->issueToken($account, StorefrontAccountToken::PURPOSE_RESET, self::RESET_TTL_MINUTES * 60);

        $this->sendEmail(
            to: $account->email,
            subject: 'Reset your password',
            body: 'Use this link within '.self::RESET_TTL_MINUTES.' minutes to reset your password: '.$token,
            subtype: 'storefront.customer.password_reset',
            templateKey: 'auth.password_reset',
            variables: [
                'user' => ['name' => $account->first_name ?: $account->email],
                'reset' => ['url' => $token, 'expires_in' => self::RESET_TTL_MINUTES],
            ],
        );

        return $token;
    }

    /**
     * @return array{account: StorefrontAccount, session: StorefrontSession, session_token: string, csrf_token: string}
     */
    public function resetPassword(string $email, string $token, string $newPassword): array
    {
        $account = StorefrontAccount::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])
            ->firstOrFail();

        $this->consumeToken($account, $token, StorefrontAccountToken::PURPOSE_RESET);

        $account->update(['password' => $newPassword]);

        // FR-ID-06: reset revokes ALL sessions.
        $this->revokeAllSessions($account);

        return $this->createSession($account->fresh());
    }

    // ─── Internals ──────────────────────────────────────────────────────

    public function linkGuestOrders(StorefrontAccount $account): int
    {
        return StorefrontOrder::query()
            ->whereNull('account_id')
            ->whereRaw('lower(email) = ?', [mb_strtolower($account->email)])
            ->update(['account_id' => $account->id]);
    }

    private function issueToken(StorefrontAccount $account, string $purpose, int $ttlSeconds): string
    {
        $plain = bin2hex(random_bytes(32));

        // Invalidate previous unconsumed tokens of the same purpose.
        StorefrontAccountToken::query()
            ->where('account_id', $account->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        StorefrontAccountToken::create([
            'account_id' => $account->id,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);

        return $plain;
    }

    private function consumeToken(StorefrontAccount $account, string $plainToken, string $purpose): void
    {
        $row = StorefrontAccountToken::query()
            ->where('account_id', $account->id)
            ->where('purpose', $purpose)
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if ($row === null || ! $row->isUsable()) {
            throw AccountAuthException::make('invalid_token', 'This link is not valid or has expired.', 422);
        }

        $row->update(['consumed_at' => now()]);
    }

    private function sendEmail(
        string $to,
        string $subject,
        string $body,
        string $subtype,
        ?string $templateKey = null,
        array $variables = [],
    ): void {
        $this->communication->sendDirect(
            subject: $subject,
            body: $body,
            recipientEmails: [$to],
            channels: ['email'],
            subtype: $subtype,
            tenantId: (function_exists('tenant') && tenant()) ? (string) tenant('id') : null,
            templateKey: $templateKey,
            templateVariables: $variables,
        );
    }
}
