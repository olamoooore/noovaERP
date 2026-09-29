<?php

namespace Modules\Storefront\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Storefront\Exceptions\CheckoutException;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\AccountService;
use Modules\Storefront\Services\CartService;
use Modules\Storefront\Services\CheckoutService;

/**
 * Public checkout + confirmation runtime (E4.1). Soft session: if a valid
 * account cookie exists, CSRF echo is mandatory (mutations with a session
 * must prove same-origin — FR-ID-09); guests rely on the SameSite=Lax cart
 * cookie. All money is re-derived server-side (FR-CO-03); the request can
 * only select the cart's contents, contact and addresses.
 */
final class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkout,
        private readonly AccountService $accounts,
        private readonly CartService $carts,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'idempotency_key' => ['sometimes', 'string', 'min:8', 'max:120'],
            'shipping_address' => ['required', 'array'],
            'shipping_address.name' => ['required', 'string', 'max:160'],
            'shipping_address.company' => ['nullable', 'string', 'max:160'],
            'shipping_address.phone' => ['nullable', 'string', 'max:40'],
            'shipping_address.address1' => ['required', 'string', 'max:255'],
            'shipping_address.address2' => ['nullable', 'string', 'max:255'],
            'shipping_address.city' => ['required', 'string', 'max:120'],
            'shipping_address.state' => ['nullable', 'string', 'max:120'],
            'shipping_address.postal_code' => ['required', 'string', 'max:20'],
            'shipping_address.country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'billing_address' => ['nullable', 'array'],
            'billing_address.name' => ['required_with:billing_address', 'string', 'max:160'],
            'billing_address.address1' => ['required_with:billing_address', 'string', 'max:255'],
            'billing_address.city' => ['required_with:billing_address', 'string', 'max:120'],
            'billing_address.postal_code' => ['required_with:billing_address', 'string', 'max:20'],
            'billing_address.country_code' => ['required_with:billing_address', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'accepted_terms' => ['sometimes', 'boolean'],
        ]);

        // Tenant policy: when checkout terms are configured, acceptance is
        // mandatory SERVER-side (the checkbox in the island is UX only).
        if (StorefrontSettings::current()->checkout_terms_url !== null && ! ($validated['accepted_terms'] ?? false)) {
            return response()->json([
                'message' => 'You must accept the store terms to complete checkout.',
                'code' => 'terms_not_accepted',
            ], 422);
        }

        $key = $request->header('Idempotency-Key') ?: ($validated['idempotency_key'] ?? null);
        if (! is_string($key) || strlen($key) < 8 || strlen($key) > 120) {
            return response()->json([
                'message' => 'An idempotency_key (8-120 chars) or Idempotency-Key header is required.',
                'code' => 'idempotency_key_required',
            ], 422);
        }

        $session = $this->accounts->resolveSession($request->cookie(AccountService::SESSION_COOKIE));
        $account = $session['account'] ?? null;

        if ($session !== null) {
            $csrfCookie = $request->cookie(AccountService::CSRF_COOKIE);
            $csrfHeader = $request->header('X-CSRF-Token');
            if (! is_string($csrfCookie) || ! is_string($csrfHeader) || ! hash_equals($csrfCookie, $csrfHeader)) {
                return response()->json([
                    'message' => 'CSRF token missing or invalid.',
                    'code' => 'storefront_csrf_mismatch',
                ], 419);
            }
        }

        $cart = $account !== null
            ? $this->carts->activeAccountCart((int) $account->id)
            : $this->carts->resolveGuestCart($request->cookie(CartService::COOKIE))[0];

        try {
            $result = $this->checkout->place(
                cart: $cart,
                contact: ['email' => $validated['email'], 'phone' => $validated['phone'] ?? $validated['shipping_address']['phone'] ?? null],
                shipping: $validated['shipping_address'],
                billing: $validated['billing_address'] ?? null,
                account: $account,
                idempotencyKey: $key,
            );
        } catch (CheckoutException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
                'errors' => $e->context ?: null,
            ], $e->status);
        }

        $order = $result['order'];

        $payload = [
            'data' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'totals' => [
                    'subtotal' => (string) $order->subtotal,
                    'tax' => (string) $order->tax_amount,
                    'total' => (string) $order->total,
                ],
                'currency' => $order->currency,
                'replay' => $result['replay'],
            ],
        ];

        if ($result['confirmation_token'] !== null) {
            $payload['data']['confirmation_token'] = $result['confirmation_token'];
        }

        return response()->json($payload, $result['replay'] ? 200 : 201);
    }

    public function confirm(string $token): JsonResponse
    {
        $order = $this->checkout->resolveByConfirmationToken($token);

        if ($order === null) {
            return response()->json([
                'message' => 'Order not found, or the link has expired.',
                'code' => 'not_found',
            ], 404);
        }

        return response()->json(['data' => $this->confirmationPayload($order)]);
    }

    private function confirmationPayload(StorefrontOrder $order): array
    {
        $addr = $order->shipping_address ?? [];

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'payment_status' => $order->payment_status,
            'placed_at' => optional($order->placed_at)->toIso8601String(),
            'currency' => $order->currency,
            'totals' => [
                'subtotal' => (string) $order->subtotal,
                'tax' => (string) $order->tax_amount,
                'total' => (string) $order->total,
            ],
            'items' => $order->items->map(fn ($i) => [
                'name' => $i->name,
                'quantity' => (float) $i->quantity,
                'line_total' => (string) $i->line_total,
            ]),
            'shipping' => array_intersect_key($addr, array_flip(['name', 'address1', 'city', 'postal_code', 'country_code'])),
            'tracking_numbers' => $order->tracking_numbers ?? [],
        ];
    }
}
