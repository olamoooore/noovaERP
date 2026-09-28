<?php

namespace Modules\Storefront\Shortcodes;

use Modules\Storefront\Exceptions\CartException;

/**
 * Tier B storefront functions (FR-CO-01, FR-ID-01, FR-DASH-01): personalised
 * shells + hydration markers, NEVER cached (§B3.4 — the engine enforces this
 * via Tier::PERSONALIZED). No cart/account DATA is server-rendered into the
 * shell; the runtime bundle fetches it from /api/v1/storefront/* with the
 * visitor's cookie session. Fallback text keeps the page meaningful without
 * JS (progressive enhancement, §B5 "No-JS" row).
 */
final class CartFunctions
{
    public static function register(StorefrontShortcodeRegistry $registry): void
    {
        $registry->register(self::addToCart());
        $registry->register(self::cart('cart', 'Cart', 'page'));
        $registry->register(self::cart('cart_drawer', 'Cart Drawer', 'drawer'));
        $registry->register(self::cartCount());
        $registry->register(self::auth('login', 'Login', 'Sign in'));
        $registry->register(self::auth('register', 'Register', 'Create account'));
        $registry->register(self::account());
    }

    /**
     * Redirect attributes are DATA, never code (§B3.5): only same-site
     * paths or http(s)/mailto URLs survive. Without this a merchant-
     * authored token could carry javascript:... and the runtime would
     * happily navigate to it.
     */
    private static function safeUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        if (preg_match('#^(https?://|mailto:)[^\s"\'<>]+$#i', $url) === 1) {
            return $url;
        }

        return null;
    }

    private static function addToCart(): Definition
    {
        return Definition::make('storefront.add_to_cart')
            ->label('Add to Cart')
            ->icon('solar:cart-large-bold-duotone')
            ->category('Cart')
            ->tier(Tier::PERSONALIZED)
            ->cache(0)
            ->version('1')
            ->help('Button that adds a product (or variant) to the shopper cart via the storefront runtime.')
            ->example('[storefront.add_to_cart product_id="12" label="Buy now"]')
            ->attributes([
                Attribute::int('product_id', 0, 1, 100000000, 'Product id (required).'),
                Attribute::int('variant', 0, 0, 100000000, 'Variant id (optional).'),
                Attribute::int('quantity', 1, 1, 999),
                Attribute::string('label', 'Add to cart', 80),
                Attribute::enum('style', ['solid', 'outline'], 'solid'),
                Attribute::string('redirect', null, 500, 'URL to open after a successful add.'),
            ])
            ->render(static function (Context $c, array $a): string {
                if ((int) $a['product_id'] <= 0) {
                    throw CartException::make('missing_product_id', 'storefront.add_to_cart requires a positive product_id.', 422);
                }

                $a['redirect'] = self::safeUrl($a['redirect']);

                return view('storefront::shortcodes.add-to-cart', [
                    'context' => $c,
                    'a' => $a,
                ])->render();
            });
    }

    private static function cart(string $fn, string $label, string $layout): Definition
    {
        $drawer = $layout === 'drawer';

        return Definition::make('storefront.'.$fn)
            ->label($label)
            ->icon('solar:cart-3-bold-duotone')
            ->category('Cart')
            ->tier(Tier::PERSONALIZED)
            ->cache(0)
            ->version('1')
            ->help('Server-rendered shell hydrated by the storefront runtime with the shopper\'s live cart.')
            ->example('[storefront.cart show_checkout_button]')
            ->attributes(array_values(array_filter([
                $drawer ? Attribute::enum('position', ['right', 'left'], 'right') : null,
                $drawer ? Attribute::bool('open_on_add', true) : null,
                Attribute::bool('show_checkout_button', false, 'Show a checkout call-to-action (checkout page lands in P2).'),
            ])))
            ->render(static fn (Context $c, array $a): string => view('storefront::shortcodes.cart', [
                'context' => $c,
                'a' => $a,
                'island' => $fn,
            ])->render());
    }

    private static function cartCount(): Definition
    {
        return Definition::make('storefront.cart_count')
            ->label('Cart Count')
            ->icon('solar:bag-4-bold-duotone')
            ->category('Cart')
            ->tier(Tier::PERSONALIZED)
            ->cache(0)
            ->version('1')
            ->help('Live cart item-count badge.')
            ->example('[storefront.cart_count label="Cart"]')
            ->attributes([
                Attribute::string('label', 'Cart', 40),
            ])
            ->render(static fn (Context $c, array $a): string => view('storefront::shortcodes.cart-count', [
                'context' => $c,
                'a' => $a,
            ])->render());
    }

    private static function auth(string $fn, string $label, string $submitLabel): Definition
    {
        $register = $fn === 'register';

        return Definition::make('storefront.'.$fn)
            ->label($label)
            ->icon($register ? 'solar:user-plus-bold-duotone' : 'solar:user-circle-bold-duotone')
            ->category('Account')
            ->tier(Tier::PERSONALIZED)
            ->cache(0)
            ->version('1')
            ->help($register
                ? 'Signup form; creates a pending account and emails a verification link.'
                : 'Login form for the shopper account session (cookie-based).')
            ->example('[storefront.'.$fn.' redirect="/w/my-shop/account"]')
            ->attributes(array_values(array_filter([
                Attribute::string('redirect', null, 500, 'URL to open after success.'),
                $register ? null : Attribute::bool('show_register_link', true),
                $register ? Attribute::bool('require_phone', false) : null,
            ])))
            ->render(static function (Context $c, array $a) use ($fn, $submitLabel): string {
                $a['redirect'] = self::safeUrl($a['redirect']);

                return view('storefront::shortcodes.auth-form', [
                    'context' => $c,
                    'a' => $a,
                    'mode' => $fn,
                    'submitLabel' => $submitLabel,
                ])->render();
            });
    }

    private static function account(): Definition
    {
        return Definition::make('storefront.account')
            ->label('Account Dashboard')
            ->icon('solar:dashboard-square-bold-duotone')
            ->category('Account')
            ->tier(Tier::PERSONALIZED)
            ->cache(0)
            ->version('1')
            ->help('Account summary shell (profile, orders count, sign out). Order/address detail widgets land with the dashboard slice.')
            ->example('[storefront.account]')
            ->attributes([
                Attribute::string('sections', 'summary', 120, 'Comma list: summary,orders,loyalty (orders/loyalty land in later slices).'),
            ])
            ->render(static fn (Context $c, array $a): string => view('storefront::shortcodes.account', [
                'context' => $c,
                'a' => $a,
            ])->render());
    }
}
