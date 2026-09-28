<?php

namespace Modules\Storefront\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Storefront\Exceptions\AccountAuthException;
use Modules\Storefront\Services\AccountService;
use Modules\Storefront\Services\CartService;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Public account identity endpoints (Appendix E §E4.1 auth group,
 * FR-ID-01..07). State-changing endpoints in the authenticated subset
 * (logout/refresh) run behind StorefrontAccountAuth.
 */
final class AuthController extends Controller
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly CartService $carts,
    ) {}

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:4096'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'marketing_email_opt_in' => ['sometimes', 'boolean'],
            'marketing_sms_opt_in' => ['sometimes', 'boolean'],
            'marketing_whatsapp_opt_in' => ['sometimes', 'boolean'],
        ]);

        try {
            $this->accounts->register(
                email: $validated['email'],
                password: $validated['password'],
                firstName: $validated['first_name'] ?? null,
                lastName: $validated['last_name'] ?? null,
                phone: $validated['phone'] ?? null,
                consent: [
                    'email' => (bool) ($validated['marketing_email_opt_in'] ?? false),
                    'sms' => (bool) ($validated['marketing_sms_opt_in'] ?? false),
                    'whatsapp' => (bool) ($validated['marketing_whatsapp_opt_in'] ?? false),
                ],
                locale: $request->input('locale'),
            );
        } catch (AccountAuthException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json([
            'data' => ['status' => 'pending_verification'],
            'message' => 'Account created. Check your inbox to verify your email.',
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:4096'],
        ]);

        try {
            $auth = $this->accounts->login(
                $validated['email'],
                $validated['password'],
                $request->ip(),
                $request->userAgent(),
            );
        } catch (AccountAuthException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        // FR-CO-02: merge the guest cart carried by this browser into the
        // now-authenticated account before responding.
        $guestToken = $request->cookie(CartService::COOKIE);
        if (is_string($guestToken) && $guestToken !== '') {
            [$guestCart] = $this->carts->resolveGuestCart($guestToken);
            if ($guestCart->account_id === null && $guestCart->items()->exists()) {
                $this->carts->mergeIntoAccount($guestCart, (int) $auth['account']->id);
            }
        }

        return $this->authResponse($auth, $request, [
            'data' => $this->accountPayload($auth['account']),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $session = $request->attributes->get('storefront_session');
        if ($session !== null) {
            $this->accounts->logout($session);
        }

        return response()->json(['data' => ['status' => 'logged_out']])
            ->cookie($this->forgetCookie(AccountService::SESSION_COOKIE, $request))
            ->cookie($this->forgetCookie(AccountService::CSRF_COOKIE, $request));
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ]);

        // Response identical whether or not the account exists (FR-ID-05).
        $this->accounts->sendPasswordReset($validated['email']);

        return response()->json([
            'data' => ['status' => 'reset_mail_sent'],
            'message' => 'If an account exists for that email, a reset link has been sent.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:8', 'max:4096', 'confirmed'],
        ]);

        try {
            $auth = $this->accounts->resetPassword($validated['email'], $validated['token'], $validated['password']);
        } catch (AccountAuthException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['message' => 'This link is not valid.', 'code' => 'invalid_token'], 422);
        }

        return $this->authResponse($auth, $request, [
            'data' => $this->accountPayload($auth['account']),
            'message' => 'Password updated.',
        ]);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'token' => ['required', 'string', 'size:64'],
        ]);

        try {
            $account = $this->accounts->verifyEmail($validated['email'], $validated['token']);
        } catch (AccountAuthException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json([
            'data' => $this->accountPayload($account),
            'message' => 'Email verified — your account is active.',
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $session = $request->attributes->get('storefront_session');

        $auth = $this->accounts->rotateSession($session, $request->ip());

        return $this->authResponse($auth, $request, [
            'data' => $this->accountPayload($auth['account']),
        ]);
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function authResponse(array $auth, Request $request, array $payload): JsonResponse
    {
        $response = response()->json($payload);
        $response->headers->set('X-Storefront-CSRF', $auth['csrf_token']);

        return $response
            ->withCookie($this->sessionCookie(AccountService::SESSION_COOKIE, $auth['session_token'], $request, true))
            ->withCookie($this->sessionCookie(AccountService::CSRF_COOKIE, $auth['csrf_token'], $request, false));
    }

    private function sessionCookie(string $name, string $value, Request $request, bool $httpOnly): Cookie
    {
        return cookie(
            $name,
            $value,
            60 * 24 * AccountService::SESSION_TTL_DAYS,
            '/',
            null,
            $request->secure() || app()->environment('production'),
            $httpOnly,
            false,
            'lax',
        );
    }

    private function forgetCookie(string $name, Request $request): Cookie
    {
        return cookie()->forget($name, '/');
    }

    private function accountPayload($account): array
    {
        return [
            'id' => $account->id,
            'email' => $account->email,
            'first_name' => $account->first_name,
            'last_name' => $account->last_name,
            'status' => $account->status,
            'email_verified' => $account->email_verified_at !== null,
        ];
    }
}
