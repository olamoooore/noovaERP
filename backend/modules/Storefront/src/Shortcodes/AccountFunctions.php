<?php

namespace Modules\Storefront\Shortcodes;

/**
 * Tier B account dashboard functions (FR-DASH-01..04, FR-ID-01 verify page):
 * personal shells ONLY — every value the shopper sees is fetched by the
 * runtime from the cookie-scoped /api/v1/storefront/account/* APIs, so a
 * cached or shared render can never carry another visitor's data (§B3.4).
 */
final class AccountFunctions
{
    public static function register(StorefrontShortcodeRegistry $registry): void
    {
        $registry->register(self::shell('storefront.account_orders', 'Order List', 'account_orders', 'Account', 'solar:receipt-bold-duotone', [
            Attribute::int('limit', 10, 1, 50, 'Orders per page.'),
        ], ['limit' => '10']));

        $registry->register(self::shell('storefront.order', 'Order Detail', 'order', 'Account', 'solar:document-bold-duotone', [
            Attribute::int('id', 0, 1, 100000000, 'Order id (defaults to the ?order= URL parameter at runtime).'),
        ], ['id' => '123']));

        $registry->register(self::shell('storefront.addresses', 'Address Book', 'addresses', 'Account', 'solar:map-point-bold-duotone', [
            Attribute::enum('mode', ['full', 'list'], 'full'),
        ]));

        $registry->register(self::shell('storefront.profile', 'Profile Editor', 'profile', 'Account', 'solar:user-speak-bold-duotone', []));

        $registry->register(self::shell('storefront.security', 'Security & Sessions', 'security', 'Account', 'solar:shield-lock-bold-duotone', []));

        $registry->register(self::shell('storefront.verify_email', 'Email Verification', 'verify_email', 'Account', 'solar:letter-bold-duotone', []));

        $registry->register(self::shell('storefront.forgot_password', 'Forgot Password', 'forgot_password', 'Account', 'solar:key-bold-duotone', []));

        $registry->register(self::shell('storefront.reset_password', 'Reset Password', 'reset_password', 'Account', 'solar:key-password-bold-duotone', []));
    }

    /**
     * @param  array<int,Attribute>  $attributes
     */
    private static function shell(
        string $key,
        string $label,
        string $island,
        string $category,
        string $icon,
        array $attributes,
        array $exampleAttrs = [],
    ): Definition {
        $example = '['.$key.(empty($exampleAttrs) ? '' : ' '.implode(' ', array_map(
            static fn ($v, $k) => $k.'="'.$v.'"',
            $exampleAttrs,
            array_keys($exampleAttrs),
        ))).']';

        return Definition::make($key)
            ->label($label)
            ->icon($icon)
            ->category($category)
            ->tier(Tier::PERSONALIZED)
            ->cache(0)
            ->version('1')
            ->help('Personalised dashboard shell ('.$label.'). Shows a sign-in prompt when the visitor is anonymous.')
            ->example($example)
            ->attributes($attributes)
            ->render(static fn (Context $c, array $a): string => view('storefront::shortcodes.dashboard-shell', [
                'island' => $island,
                'label' => $label,
                'a' => $a,
            ])->render());
    }
}
