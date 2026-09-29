<?php

namespace Modules\Storefront\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Storefront\Exceptions\CheckoutException;
use Modules\Storefront\Services\AccountService;
use Modules\Storefront\Services\StorefrontPaymentService;
use Modules\Storefront\Support\PublicUrl;

/**
 * Public payment runtime endpoints (E4.1/E4.6 counterpart for the storefront
 * runtime). Soft session like CheckoutController: valid account cookie =>
 * CSRF echo mandatory; guests authenticate per-order with their one-time
 * confirmation token. Gateway "paid" is never taken from the browser.
 */
final class PayController extends Controller
{
    public function __construct(
        private readonly StorefrontPaymentService $payments,
        private readonly AccountService $accounts,
    ) {}

    public function methods(): JsonResponse
    {
        return response()->json(['data' => $this->payments->availableMethods()]);
    }

    public function pay(Request $request, int $order): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['nullable', 'string', 'min:32', 'max:64'],
            'gateway' => ['nullable', 'string', 'in:stripe,hitpay,paypal'],
            'success_url' => ['nullable', 'string', 'max:500'],
            'cancel_url' => ['nullable', 'string', 'max:500'],
        ]);

        $session = $this->sessionGuard($request);
        if ($session === false) {
            return $this->csrfFailure();
        }
        $account = $session['account'] ?? null;

        try {
            $model = $this->payments->resolvePayableOrder($order, $validated['token'] ?? null, $account);

            $intent = $this->payments->createIntentForOrder(
                $model,
                $validated['gateway'] ?? null,
                PublicUrl::sanitize($validated['success_url'] ?? null),
                PublicUrl::sanitize($validated['cancel_url'] ?? null),
            );
        } catch (CheckoutException $e) {
            return $this->fail($e);
        }

        return response()->json(['data' => [
            'order_number' => $model->order_number,
            'amount' => (string) $model->total,
            'currency' => $model->currency,
            ...$intent,
        ]]);
    }

    public function confirm(Request $request, int $order): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['nullable', 'string', 'min:32', 'max:64'],
            'transaction_id' => ['required', 'string', 'max:191'],
        ]);

        $session = $this->sessionGuard($request);
        if ($session === false) {
            return $this->csrfFailure();
        }
        $account = $session['account'] ?? null;

        try {
            $model = $this->payments->resolveOwnedOrder($order, $validated['token'] ?? null, $account);
            $result = $this->payments->confirm($model, $validated['transaction_id']);
        } catch (CheckoutException $e) {
            return $this->fail($e);
        }

        return response()->json(['data' => $result]);
    }

    /**
     * @return array|false false => CSRF mismatch for a session request
     */
    private function sessionGuard(Request $request): array|false
    {
        $session = $this->accounts->resolveSession($request->cookie(AccountService::SESSION_COOKIE));

        if ($session === null) {
            return [];
        }

        $csrfCookie = $request->cookie(AccountService::CSRF_COOKIE);
        $csrfHeader = $request->header('X-CSRF-Token');

        if (! is_string($csrfCookie) || ! is_string($csrfHeader) || ! hash_equals($csrfCookie, $csrfHeader)) {
            return false;
        }

        return $session;
    }

    private function csrfFailure(): JsonResponse
    {
        return response()->json([
            'message' => 'CSRF token missing or invalid.',
            'code' => 'storefront_csrf_mismatch',
        ], 419);
    }

    private function fail(CheckoutException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => $e->errorCode,
        ], $e->status);
    }
}
