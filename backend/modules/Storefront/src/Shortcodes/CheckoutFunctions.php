<?php

namespace Modules\Storefront\Shortcodes;

use Modules\Storefront\Support\PublicUrl;

/**
 * Tier B checkout functions (FR-CO-04/10/11, E3.3). Shells only: the
 * runtime hydrates them against the cart/checkout/pay APIs. Nothing
 * personal is server-rendered, and every authoritative decision (server
 * totals, terms acceptance, idempotency, gateway truth) stays on the
 * server — the island is UX.
 */
final class CheckoutFunctions
{
    public static function register(StorefrontShortcodeRegistry $registry): void
    {
        $registry->register(self::checkout());
        $registry->register(self::orderConfirmation());
        $registry->register(self::payNow());
        $registry->register(self::paymentMethods());
    }

    private static function checkout(): Definition
    {
        return Definition::make('storefront.checkout')
            ->label('Checkout')
            ->icon('solar:card-shopping-bold-duotone')
            ->category('Checkout')
            ->tier(Tier::PERSONALIZED)
            ->cache(0)
            ->version('1')
            ->help('Full checkout flow (contact, address, payment method, review) hydrated from the cart/checkout APIs. Place on a dedicated checkout page.')
            ->example('[storefront.checkout]')
            ->attributes([
                Attribute::string('terms_url', null, 500, 'Overrides the store settings terms URL for this block.'),
                Attribute::bool('show_billing', false, 'Ask for a separate billing address.'),
            ])
            ->render(static function (Context $c, array $a): string {
                return view('storefront::shortcodes.checkout', [
                    'termsUrl' => PublicUrl::sanitize($a['terms_url']),
                    'showBilling' => (bool) $a['show_billing'],
                ])->render();
            });
    }

    private static function orderConfirmation(): Definition
    {
        return Definition::make('storefront.order_confirmation')
            ->label('Order Confirmation')
            ->icon('solar:check-circle-bold-duotone')
            ->category('Checkout')
            ->tier(Tier::PERSONALIZED)
            ->cache(0)
            ->version('1')
            ->help('Thank-you page. Reads the one-time ?sfconf=… token from the URL; survives refresh (FR-CO-10).')
            ->example('[storefront.order_confirmation]')
            ->attributes([])
            ->render(static fn (): string => view('storefront::shortcodes.order-confirmation')->render());
    }

    private static function payNow(): Definition
    {
        return Definition::make('storefront.pay_now')
            ->label('Pay Now')
            ->icon('solar:wallet-money-bold-duotone')
            ->category('Checkout')
            ->tier(Tier::PERSONALIZED)
            ->cache(0)
            ->version('1')
            ->help('Retry payment for an unpaid order from the page URL (?sfconf= token) or a logged-in account order.')
            ->example('[storefront.pay_now]')
            ->attributes([])
            ->render(static fn (): string => view('storefront::shortcodes.pay-now')->render());
    }

    /**
     * Server-decided payment method list (PAY-R3 / E3.3 `storefront.payment_
     * methods`): launch gateways + enabled offline methods with their
     * merchant instructions. Public content — nothing personal rendered; the
     * authoritative availability check runs again on every API call anyway.
     */
    private static function paymentMethods(): Definition
    {
        return Definition::make('storefront.payment_methods')
            ->label('Payment Methods')
            ->icon('solar:banknote-2-bold-duotone')
            ->category('Checkout')
            ->tier(Tier::CACHEABLE)
            ->cache(0)
            ->version('1')
            ->help('Lists the payment methods currently available for this store, including offline instructions (bank transfer details, pay-at-collection).')
            ->example('[storefront.payment_methods]')
            ->attributes([])
            ->render(static function (): string {
                $methods = \Modules\Storefront\Services\StorefrontPaymentService::methodsSnapshot();

                $items = '';
                foreach ($methods as $m) {
                    $label = htmlspecialchars((string) $m['display_name'], ENT_QUOTES);
                    $extra = '';
                    if (($m['type'] ?? 'gateway') === 'offline' && ! empty($m['instructions'])) {
                        $extra = '<p class="sf-method__instructions">'.nl2br(htmlspecialchars((string) $m['instructions'], ENT_QUOTES)).'</p>';
                    }
                    $items .= '<li class="sf-method sf-method--'.htmlspecialchars((string) ($m['type'] ?? 'gateway'), ENT_QUOTES).'">'
                        .'<span class="sf-method__name">'.$label.'</span>'.$extra.'</li>';
                }

                return $items === ''
                    ? '<p class="sf-payment-methods__empty">No payment methods are configured yet.</p>'
                    : '<ul class="sf-payment-methods">'.$items.'</ul>';
            });
    }
}
