<?php

namespace Modules\Storefront\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Storefront\Models\StorefrontCart;

/**
 * Authenticated account summary (E4.2 GET /storefront/account) — the probe
 * that proves the cookie session + per-request status re-check (FR-ID-08)
 * and CSRF enforcement (FR-ID-09). Dashboard functions (orders/addresses/
 * profile/security) build on this in the next slice.
 */
final class AccountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $account = $request->attributes->get('storefront_account');

        $activeCart = StorefrontCart::query()
            ->where('account_id', $account->id)
            ->where('status', 'active')
            ->withCount('items')
            ->first();

        return response()->json([
            'data' => [
                'id' => $account->id,
                'email' => $account->email,
                'first_name' => $account->first_name,
                'last_name' => $account->last_name,
                'phone' => $account->phone,
                'status' => $account->status,
                'email_verified' => $account->email_verified_at !== null,
                'last_login_at' => optional($account->last_login_at)->toIso8601String(),
                'orders_count' => $account->orders()->count(),
                'active_cart_items' => (int) ($activeCart?->items_count ?? 0),
                'consent' => [
                    'email' => (bool) $account->marketing_email_opt_in,
                    'sms' => (bool) $account->marketing_sms_opt_in,
                    'whatsapp' => (bool) $account->marketing_whatsapp_opt_in,
                ],
                'sessions_endpoint' => '/api/v1/storefront/account/sessions',
            ],
        ]);
    }
}
