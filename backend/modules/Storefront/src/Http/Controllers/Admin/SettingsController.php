<?php

namespace Modules\Storefront\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Storefront\Models\StorefrontSettings;

/**
 * Tenant admin: storefront settings (FR-ADM-02, FR-MOD-08).
 *
 * Route-level gates (live middleware chain, verified by SF001 HTTP tests):
 *   auth:sanctum + entitlement:storefront + RequirePermission storefront.view / storefront.settings
 */
class SettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->payload(StorefrontSettings::current())]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'currency_code' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'tax_display' => ['sometimes', 'in:inclusive,exclusive'],
            'guest_checkout' => ['sometimes', 'boolean'],
            'allow_backorder' => ['sometimes', 'boolean'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'cart_ttl_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'checkout_terms_url' => ['nullable', 'string', 'max:2048'],
            'policy_returns_url' => ['nullable', 'string', 'max:2048'],
            'policy_shipping_url' => ['nullable', 'string', 'max:2048'],
            'policy_privacy_url' => ['nullable', 'string', 'max:2048'],
            'notification_settings' => ['nullable', 'array'],
            'notification_settings.*' => ['boolean'],
        ]);

        $settings = StorefrontSettings::current();
        $settings->update($validated);

        return response()->json(['data' => $this->payload($settings->fresh())]);
    }

    private function payload(StorefrontSettings $settings): array
    {
        return [
            'enabled' => $settings->enabled,
            'currency_code' => $settings->currency_code,
            'tax_display' => $settings->tax_display,
            'guest_checkout' => $settings->guest_checkout,
            'allow_backorder' => $settings->allow_backorder,
            'low_stock_threshold' => $settings->low_stock_threshold,
            'cart_ttl_days' => $settings->cart_ttl_days,
            'checkout_terms_url' => $settings->checkout_terms_url,
            'policy_returns_url' => $settings->policy_returns_url,
            'policy_shipping_url' => $settings->policy_shipping_url,
            'policy_privacy_url' => $settings->policy_privacy_url,
            'notification_settings' => $settings->notification_settings,
            'runtime_version' => $settings->runtime_version,
        ];
    }
}
