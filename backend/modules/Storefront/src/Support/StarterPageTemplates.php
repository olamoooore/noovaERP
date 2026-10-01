<?php

declare(strict_types=1);

namespace Modules\Storefront\Support;

/**
 * Starter store page templates (MRD Appendix-B §B4.4, P5).
 *
 * "Recommended pages are provided as starter page templates (not hardcoded
 * designs)": each template is an ordinary WebsiteBuilder page whose content
 * embeds Storefront functions. TEMPLATES CONTAIN NO TENANT-SPECIFIC CONTENT
 * (G5) — placeholders and function tokens only; merchants edit/publish as
 * with any builder page.
 */
final class StarterPageTemplates
{
    /**
     * @param  string|null  $siteSlug  when known, product grid tokens get a
     *         product_page locator pointing at THIS site's starter product
     *         page — cards become real crawlable <a href>s (FR-SF-12/A-17:
     *         "crawlable from the tenant domain" without JS).
     * @return list<array{slug: string, title: string, html: string}>
     */
    public static function pages(?string $siteSlug = null): array
    {
        $productPage = $siteSlug === null || $siteSlug === ''
            ? ''
            : ' product_page="'.htmlspecialchars('/w/'.$siteSlug.'/product', ENT_QUOTES, 'UTF-8').'"';

        $pages = [
            [
                'slug' => 'shop',
                'title' => 'Shop',
                'html' => '<section><h1>Shop</h1>[storefront.products limit="12" columns="3"'.$productPage.']</section>',
            ],
            [
                'slug' => 'category',
                'title' => 'Category',
                'html' => '<section><h1>Categories</h1>[storefront.categories]</section><section>[storefront.category_products'.$productPage.']</section>',
            ],
            [
                'slug' => 'product',
                'title' => 'Product',
                'html' => '<section>[storefront.product layout="detail"]</section>',
            ],
            [
                'slug' => 'cart',
                'title' => 'Cart',
                'html' => '<section><h1>Your Cart</h1>[storefront.cart show_checkout_button]</section>',
            ],
            [
                'slug' => 'checkout',
                'title' => 'Checkout',
                'html' => '<section><h1>Checkout</h1>[storefront.checkout]</section>',
            ],
            [
                'slug' => 'account',
                'title' => 'Account',
                'html' => '<section><h1>My Account</h1>[storefront.account]</section><section>[storefront.account_orders]</section><section>[storefront.addresses]</section>',
            ],
            [
                'slug' => 'order-confirmation',
                'title' => 'Order Confirmation',
                'html' => '<section>[storefront.order_confirmation]</section>',
            ],
            [
                'slug' => 'search',
                'title' => 'Search',
                'html' => '<section><h1>Search</h1>[storefront.search]</section>',
            ],
        ];

        return $pages;
    }
}
