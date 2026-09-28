<?php

namespace Modules\Storefront\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\TaxResolutionService;
use Illuminate\Support\Facades\DB;
use Modules\Storefront\Exceptions\CartException;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontCartItem;

/**
 * SERVER-AUTHORITATIVE cart (FR-CO-02/03, A-10/A-11).
 *
 * - Identity: an account session (P1 auth slice) or a hashed guest token
 *   in an httpOnly SameSite=Lax cookie. Nothing is trusted from the client
 *   except WHICH product/variant/qty the shopper asked for — prices, tax,
 *   availability and totals are recomputed from the ERP on every response
 *   (tampered client prices are structurally ignored, A-10).
 * - Pricing goes through the canonical Product::getPriceForQuantity()
 *   (tiered/customer price lists honored once sessions exist) and tax
 *   through the platform TaxResolutionService — no parallel math (SOP §4).
 * - Exactly one ACTIVE cart per identity (DB partial uniques; SF007).
 */
final class CartService
{
    public const COOKIE = 'sf_guest';

    public const TOKEN_BYTES = 32;

    public const MAX_LINES = 100;

    public const MAX_QTY = 999;

    public function __construct(
        private readonly TaxResolutionService $taxResolver,
        private readonly StorefrontCache $cache,
    ) {}

    public function newGuestToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    public function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Resolve (or create) the active guest cart for a bearer token.
     * Invalid/absent/expired tokens yield a brand-new cart AND a new plain
     * token to set as a cookie; the old cart is marked expired.
     *
     * @return array{0: StorefrontCart, 1: ?string} [cart, newPlainTokenOrNull]
     *                                              the second element is non-null ONLY when a fresh cookie must be issued.
     */
    public function resolveGuestCart(?string $plainToken): array
    {
        if ($plainToken !== null && preg_match('/^[a-f0-9]{64}$/', $plainToken) === 1) {
            $hash = $this->hashToken($plainToken);
            $cart = StorefrontCart::query()
                ->where('guest_token_hash', $hash)
                ->where('status', 'active')
                ->first();

            if ($cart !== null) {
                if ($cart->expires_at === null || $cart->expires_at->isFuture()) {
                    return [$cart, null]; // existing token still valid; reuse it
                }

                $cart->update(['status' => 'expired']);
            }
        }

        // Either no/invalid/expired token: mint a fresh cart + cookie value.
        $token = $this->newGuestToken();
        $settings = \Modules\Storefront\Models\StorefrontSettings::current();

        $cart = StorefrontCart::create([
            'guest_token_hash' => $this->hashToken($token),
            'currency' => $settings->currency_code,
            'status' => 'active',
            'expires_at' => now()->addDays(max(1, (int) $settings->cart_ttl_days)),
        ]);

        return [$cart, $token];
    }

    public function createForAccount(int $accountId): StorefrontCart
    {
        $settings = \Modules\Storefront\Models\StorefrontSettings::current();

        return StorefrontCart::create([
            'account_id' => $accountId,
            'currency' => $settings->currency_code,
            'status' => 'active',
            'expires_at' => now()->addDays(max(1, (int) $settings->cart_ttl_days)),
        ]);
    }

    public function activeAccountCart(int $accountId): StorefrontCart
    {
        $settings = \Modules\Storefront\Models\StorefrontSettings::current();

        return StorefrontCart::query()->firstOrCreate(
            ['account_id' => $accountId, 'status' => 'active'],
            [
                'currency' => $settings->currency_code,
                'expires_at' => now()->addDays(max(1, (int) $settings->cart_ttl_days)),
            ]
        );
    }

    /**
     * Add (accumulate) a line. Validates purchasability + stock; prices are
     * NEVER taken from the request.
     */
    public function addOrUpdateItem(StorefrontCart $cart, int $productId, ?int $variantId, float $quantity): StorefrontCartItem
    {
        if ($quantity < 1 || $quantity > self::MAX_QTY) {
            throw CartException::make('invalid_quantity', 'Quantity must be between 1 and {self::MAX_QTY}.', 422);
        }

        $product = $this->purchasableProduct($productId);
        $variant = $this->resolveVariant($product, $variantId);

        $this->assertStock($product, $quantity);

        $line = StorefrontCartItem::query()
            ->where('cart_id', $cart->id)
            ->where('product_id', $product->id)
            ->where(function ($q) use ($variant) {
                $variant === null ? $q->whereNull('variant_id') : $q->where('variant_id', $variant->id);
            })
            ->first();

        if ($line !== null) {
            $target = min(self::MAX_QTY, (float) $line->quantity + $quantity);
            $line->update(['quantity' => $target]);

            return $line->refresh();
        }

        if ($cart->items()->count() >= self::MAX_LINES) {
            throw CartException::make('cart_line_limit', 'A cart may hold at most '.self::MAX_LINES.' distinct lines.', 422);
        }

        return StorefrontCartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'quantity' => $quantity,
        ]);
    }

    /**
     * Set absolute quantity (0 removes the line).
     */
    public function setItemQuantity(StorefrontCart $cart, int $itemId, float $quantity): ?StorefrontCartItem
    {
        $line = $this->ownedItem($cart, $itemId);

        if ($quantity <= 0) {
            $line->delete();

            return null;
        }

        if ($quantity > self::MAX_QTY) {
            throw CartException::make('invalid_quantity', 'Quantity exceeds the maximum allowed.', 422);
        }

        $product = $this->purchasableProduct((int) $line->product_id);
        $this->assertStock($product, $quantity);

        $line->update(['quantity' => $quantity]);

        return $line->refresh();
    }

    public function removeItem(StorefrontCart $cart, int $itemId): void
    {
        $this->ownedItem($cart, $itemId)->delete();
    }

    /**
     * Server-authoritative quote of the cart. Recomputed from ERP data on
     * EVERY call (prices, tiers, tax, availability). Bounded query count:
     * items + products + variants (3) regardless of line count.
     *
     * @return array<string,mixed>
     */
    public function quote(StorefrontCart $cart, ?int $customerId = null): array
    {
        $lines = $cart->items()->get();
        $productIds = $lines->pluck('product_id')->unique()->all();
        $variantIds = $lines->pluck('variant_id')->filter()->unique()->all();

        $products = Product::query()->whereIn('id', $productIds ?: [0])->get()->keyBy('id');
        $variants = $variantIds === [] ? collect() : ProductVariant::query()->whereIn('id', $variantIds)->get()->keyBy('id');

        $items = [];
        $netTotal = 0.0;
        $taxTotal = 0.0;
        $grossTotal = 0.0;
        $breakdown = [];

        foreach ($lines as $line) {
            $product = $products->get($line->product_id);

            if ($product === null) {
                continue;
            }

            $variant = $line->variant_id !== null ? $variants->get($line->variant_id) : null;
            $quantity = (float) $line->quantity;

            // Canonical pricing (tier/customer/variant-aware) — server side.
            $unit = (float) $product->getPriceForQuantity(
                (int) max(1, floor($quantity)),
                $customerId,
                now(),
                $variant?->id,
            );

            $taxInfo = $this->taxResolver->resolveTaxForProduct($product, $variant);
            $calc = $this->taxResolver->calculateTax($unit * $quantity, $taxInfo['rate'], $taxInfo['is_inclusive']);

            $netTotal += $calc['net'];
            $taxTotal += $calc['tax'];
            $grossTotal += $calc['gross'];

            $rateKey = (string) $taxInfo['rate'];
            $breakdown[$rateKey] ??= ['rate' => (float) $rateKey, 'inclusive' => $taxInfo['is_inclusive'], 'tax' => 0.0];
            $breakdown[$rateKey]['tax'] = round($breakdown[$rateKey]['tax'] + $calc['tax'], 2);

            $items[] = [
                'id' => $line->id,
                'product_id' => $product->id,
                'variant_id' => $variant?->id,
                'name' => $product->name,
                'sku' => $variant?->sku ?? $product->sku,
                'quantity' => $quantity,
                'unit_price' => self::money($unit),
                'tax_rate' => (float) $rateKey,
                'tax_amount' => self::money($calc['tax']),
                'line_net' => self::money($calc['net']),
                'line_total' => self::money($calc['gross']),
                'in_stock' => ! $product->track_inventory || (int) $product->stock_quantity > 0,
            ];
        }

        return [
            'cart_id' => $cart->id,
            'status' => $cart->status,
            'currency' => $cart->currency ?? \Modules\Storefront\Models\StorefrontSettings::current()->currency_code,
            'items' => $items,
            'item_count' => array_sum(array_column($items, 'quantity')),
            'totals' => [
                'subtotal' => self::money($netTotal),
                'tax' => self::money($taxTotal),
                'total' => self::money($grossTotal),
            ],
            'tax_breakdown' => array_values(array_map(
                static fn (array $b): array => $b + ['tax' => self::money((float) $b['tax'])],
                $breakdown
            )),
            'expires_at' => optional($cart->expires_at)->toIso8601String(),
        ];
    }

    /**
     * Money fields are emitted as fixed 2-decimal STRINGS. This is the
     * correct public contract for currency: it prevents both float drift
     * and JSON int-coercion (round(51.0) would otherwise serialize as 51),
     * and clients must never do float math on money.
     */
    private static function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Merge a guest cart into the account's active cart (FR-CO-02 "merged on
     * login"). Quantities accumulate per line; the guest cart is consumed.
     */
    public function mergeIntoAccount(StorefrontCart $guestCart, int $accountId): StorefrontCart
    {
        if ($guestCart->account_id !== null && (int) $guestCart->account_id === $accountId) {
            return $guestCart;
        }

        return DB::transaction(function () use ($guestCart, $accountId) {
            $accountCart = $this->activeAccountCart($accountId);

            $guestLines = $guestCart->items()->get();

            if ((int) $guestCart->id === (int) $accountCart->id) {
                return $accountCart;
            }

            foreach ($guestLines as $line) {
                $existing = StorefrontCartItem::query()
                    ->where('cart_id', $accountCart->id)
                    ->where('product_id', $line->product_id)
                    ->where(function ($q) use ($line) {
                        $line->variant_id === null ? $q->whereNull('variant_id') : $q->where('variant_id', $line->variant_id);
                    })
                    ->first();

                if ($existing !== null) {
                    $existing->update(['quantity' => min(CartService::MAX_QTY, (float) $existing->quantity + (float) $line->quantity)]);

                    continue;
                }

                if ($accountCart->items()->count() < self::MAX_LINES) {
                    StorefrontCartItem::create([
                        'cart_id' => $accountCart->id,
                        'product_id' => $line->product_id,
                        'variant_id' => $line->variant_id,
                        'quantity' => $line->quantity,
                    ]);
                }
            }

            // Consume the guest cart (slot freed; token dead on next read).
            $guestCart->update(['status' => 'ordered']);

            $this->cache->bumpVersion(function_exists('tenant') && tenant() ? (string) tenant('id') : null);

            return $accountCart;
        });
    }

    // ─── Internals ──────────────────────────────────────────────────────

    private function purchasableProduct(int $productId): Product
    {
        $product = Product::query()
            ->where('id', $productId)
            ->where('is_active', true)
            ->where('show_on_website', true)
            ->where(function ($q) {
                $q->whereNull('status')->orWhere('status', '=', Product::STATUS_ACTIVE ?? 'active');
            })
            ->first();

        if ($product === null) {
            throw CartException::make('product_not_available', 'This product is not available.', 422);
        }

        return $product;
    }

    private function resolveVariant(Product $product, ?int $variantId): ?ProductVariant
    {
        if ($variantId === null) {
            return null;
        }

        $variant = ProductVariant::query()
            ->where('id', $variantId)
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->first();

        if ($variant === null) {
            throw CartException::make('variant_not_available', 'This option is not available for the product.', 422);
        }

        return $variant;
    }

    private function assertStock(Product $product, float $quantity): void
    {
        if (! $product->track_inventory) {
            return;
        }

        $available = (int) $product->stock_quantity;

        if ($available >= $quantity) {
            return;
        }

        $settings = \Modules\Storefront\Models\StorefrontSettings::current();

        if ($settings->allow_backorder) {
            return; // tenant policy permits selling beyond on-hand (labeling in P2)
        }

        throw CartException::make('insufficient_stock', 'Not enough stock for the requested quantity.', 422, [
            'available' => max(0, $available),
        ]);
    }

    private function ownedItem(StorefrontCart $cart, int $itemId): StorefrontCartItem
    {
        // IDOR guard: the route-bound id must belong to THIS cart.
        $line = StorefrontCartItem::query()
            ->where('id', $itemId)
            ->where('cart_id', $cart->id)
            ->first();

        if ($line === null) {
            throw CartException::make('item_not_found', 'Cart line not found.', 404);
        }

        return $line;
    }
}
