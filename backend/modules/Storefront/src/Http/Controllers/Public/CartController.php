<?php

namespace Modules\Storefront\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Storefront\Exceptions\CartException;
use Modules\Storefront\Services\CartService;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Public storefront cart runtime (Appendix E §E4.1).
 *
 * Registered in routes/tenant.php's PUBLIC group (tenant resolved by host,
 * no auth) behind `entitlement:storefront` — an unentitled tenant's calls
 * 403 here and its published pages strip the functions (A-04). Identity is
 * an httpOnly, SameSite=Lax, host-scoped guest cookie carrying a random
 * token; only its SHA-256 is stored (the raw token is never persisted).
 *
 * Prices/totals are NEVER accepted from the client (FR-CO-03, A-10): this
 * controller takes product/variant/quantity only and returns a server-side
 * re-quote from the ERP on every mutation.
 */
final class CartController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
    ) {}

    public function show(Request $request): JsonResponse
    {
        [$cart, $newToken] = $this->resolveCart($request);

        return $this->quoteResponse($cart, $newToken, $request);
    }

    public function storeItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'variant_id' => ['nullable', 'integer', 'min:1'],
            'quantity' => ['sometimes', 'numeric', 'min:1', 'max:'.CartService::MAX_QTY],
            // price/total fields are intentionally NOT validated — ignored.
        ]);

        [$cart, $newToken] = $this->resolveCart($request);

        try {
            $this->carts->addOrUpdateItem(
                $cart,
                (int) $validated['product_id'],
                isset($validated['variant_id']) ? (int) $validated['variant_id'] : null,
                (float) ($validated['quantity'] ?? 1),
            );
        } catch (CartException $e) {
            return $this->fail($e, $cart, $newToken, $request);
        }

        return $this->quoteResponse($cart, $newToken, $request);
    }

    public function updateItem(Request $request, int $item): JsonResponse
    {
        $validated = $request->validate([
            'quantity' => ['required', 'numeric', 'min:0', 'max:'.CartService::MAX_QTY],
        ]);

        [$cart, $newToken] = $this->resolveCart($request);

        try {
            $this->carts->setItemQuantity($cart, $item, (float) $validated['quantity']);
        } catch (CartException $e) {
            return $this->fail($e, $cart, $newToken, $request);
        }

        return $this->quoteResponse($cart, $newToken, $request);
    }

    public function destroyItem(Request $request, int $item): JsonResponse
    {
        [$cart, $newToken] = $this->resolveCart($request);

        try {
            $this->carts->removeItem($cart, $item);
        } catch (CartException $e) {
            return $this->fail($e, $cart, $newToken, $request);
        }

        return $this->quoteResponse($cart, $newToken, $request);
    }

    /**
     * @return array{0: \Modules\Storefront\Models\StorefrontCart, 1: ?string}
     */
    private function resolveCart(Request $request): array
    {
        $token = $request->cookie(CartService::COOKIE);
        $token = is_string($token) ? $token : null;

        return $this->carts->resolveGuestCart($token);
    }

    private function quoteResponse($cart, ?string $newToken, Request $request): JsonResponse
    {
        $quote = $this->carts->quote($cart);

        $response = response()->json(['data' => $quote]);

        if ($newToken !== null) {
            $response->withCookie($this->guestCookie($newToken, $request));
        }

        return $response;
    }

    private function fail(CartException $e, $cart, ?string $newToken, Request $request): JsonResponse
    {
        $response = response()->json([
            'message' => $e->getMessage(),
            'code' => $e->errorCode,
            'errors' => $e->context ?: null,
        ], $e->status);

        // Still issue the cookie on failure so a retry isn't a brand-new cart.
        if ($newToken !== null) {
            $response->withCookie($this->guestCookie($newToken, $request));
        }

        return $response;
    }

    private function guestCookie(string $token, Request $request): Cookie
    {
        $settings = \Modules\Storefront\Models\StorefrontSettings::current();

        return cookie(
            CartService::COOKIE,
            $token,
            60 * 24 * max(1, (int) $settings->cart_ttl_days), // minutes
            '/',
            null,
            $request->secure() || app()->environment('production'), // Secure on HTTPS/prod
            true,   // httpOnly  (D3: no tokens in localStorage)
            false,  // raw
            'lax',  // SameSite
        );
    }
}
