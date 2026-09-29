<?php

namespace Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-tenant storefront settings singleton (mirrors the established
 * InventorySettings::current() convention).
 */
class StorefrontSettings extends Model
{
    protected $table = 'storefront_settings';

    protected $fillable = [
        'enabled',
        'currency_code',
        'tax_display',
        'guest_checkout',
        'allow_backorder',
        'low_stock_threshold',
        'cart_ttl_days',
        'checkout_terms_url',
        'policy_returns_url',
        'policy_shipping_url',
        'policy_privacy_url',
        'notification_settings',
        'payment_settings',
        'runtime_version',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'guest_checkout' => 'boolean',
        'allow_backorder' => 'boolean',
        'low_stock_threshold' => 'integer',
        'cart_ttl_days' => 'integer',
        'notification_settings' => 'array',
        'payment_settings' => 'array',
    ];

    public static function current(): self
    {
        $settings = static::query()->first();

        if ($settings !== null) {
            return $settings;
        }

        // create([]) leaves DB-column defaults unread until refresh() —
        // re-read so callers always get the real materialised defaults.
        return static::query()->create([])->refresh();
    }
}
