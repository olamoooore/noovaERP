<?php

namespace Modules\Storefront\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Storefront\Enums\ActorType;
use Modules\Storefront\Enums\OrderStatus;
use Modules\Storefront\Exceptions\InvalidOrderTransitionException;
use Modules\Storefront\Models\StorefrontAccount;
use Modules\Storefront\Models\StorefrontAddress;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontSession;
use Modules\Storefront\Services\AccountService;
use Modules\Storefront\Services\CartService;
use Modules\Storefront\Services\OrderService;
use Modules\Storefront\Support\SetsStorefrontCookies;

/**
 * Tenant-customer dashboard APIs (Appendix E §E4.2), all behind
 * StorefrontAccountAuth (session + per-request status re-check + CSRF).
 * Everything here is scoped to the AUTHENTICATED storefront account —
 * ids from the client are never trusted past that scope.
 */
final class AccountController extends Controller
{
    use SetsStorefrontCookies;

    private const MAX_ADDRESSES = 25;

    public function __construct(
        private readonly AccountService $accounts,
        private readonly OrderService $orders,
        private readonly CartService $carts,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $account = $this->account($request);

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
            ],
        ]);
    }

    // ─── Orders (FR-DASH-02) ────────────────────────────────────────────

    public function orders(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $page = $account->orders()
            ->with('items:id,order_id,name,quantity,unit_price,line_total')
            ->latest('id')
            ->paginate(min(50, max(1, (int) $request->integer('per_page', 10))));

        return response()->json([
            'data' => collect($page->items())->map(fn (StorefrontOrder $o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'placed_at' => optional($o->placed_at)->toIso8601String(),
                'status' => $o->status,
                'payment_status' => $o->payment_status,
                'total' => (string) $o->total,
                'currency' => $o->currency,
                'items' => $o->items->map(fn ($i) => [
                    'name' => $i->name,
                    'quantity' => (float) $i->quantity,
                    'line_total' => (string) $i->line_total,
                ]),
            ]),
            'meta' => ['page' => $page->currentPage(), 'total' => $page->total()],
        ]);
    }

    public function order(Request $request, int $order): JsonResponse
    {
        $model = $this->ownedOrder($request, $order);

        return response()->json([
            'data' => [
                'id' => $model->id,
                'order_number' => $model->order_number,
                'status' => $model->status,
                'payment_status' => $model->payment_status,
                'placed_at' => optional($model->placed_at)->toIso8601String(),
                'totals' => [
                    'subtotal' => (string) $model->subtotal,
                    'tax' => (string) $model->tax_amount,
                    'shipping' => (string) $model->shipping_amount,
                    'total' => (string) $model->total,
                ],
                'currency' => $model->currency,
                'items' => $model->items->map(fn ($i) => [
                    'name' => $i->name, 'sku' => $i->sku, 'quantity' => (float) $i->quantity,
                    'fulfilled_quantity' => (float) $i->fulfilled_quantity,
                    'unit_price' => (string) $i->unit_price, 'line_total' => (string) $i->line_total,
                ]),
                'history' => $model->statusHistory()->get()->map(fn ($h) => [
                    'from' => $h->from_status, 'to' => $h->to_status,
                    'at' => optional($h->created_at)->toIso8601String(),
                ]),
                'tracking_numbers' => $model->tracking_numbers ?? [],
                'invoice_id' => $model->invoice_id,
            ],
        ]);
    }

    public function cancelOrder(Request $request, int $order): JsonResponse
    {
        $account = $this->account($request);
        $model = $this->ownedOrder($request, $order);

        try {
            $updated = $this->orders->transitionOrder(
                $model,
                OrderStatus::CANCELLED,
                ActorType::ACCOUNT,
                (int) $account->id,
                'cancelled from account dashboard',
            );
        } catch (InvalidOrderTransitionException $e) {
            // Only unpaid orders can self-cancel; anything else must go
            // through staff/refund paths (money-safety, FR-ORD-02).
            return response()->json([
                'message' => 'This order can no longer be cancelled.',
                'code' => 'invalid_transition',
                'allowed' => $e->allowedFrom,
            ], 409);
        }

        return response()->json(['data' => ['id' => $updated->id, 'status' => $updated->status]]);
    }

    public function reorder(Request $request, int $order): JsonResponse
    {
        $model = $this->ownedOrder($request, $order);
        $cart = $this->carts->activeAccountCart((int) $this->account($request)->id);

        $added = 0;
        foreach ($model->items as $item) {
            if ($item->product_id === null) {
                continue; // product deleted or bundle line — not re-orderable
            }

            try {
                $this->carts->addOrUpdateItem($cart, (int) $item->product_id, $item->variant_id ? (int) $item->variant_id : null, min(CartService::MAX_QTY, max(1, (float) $item->quantity)));
                $added++;
            } catch (\Throwable) {
                // Unpurchasable now (hidden/untracked) — skip, report count.
            }
        }

        return response()->json(['data' => $this->carts->quote($cart)]);
    }

    // ─── Addresses (FR-DASH-03) ─────────────────────────────────────────

    public function addresses(Request $request): JsonResponse
    {
        $account = $this->account($request);

        return response()->json([
            'data' => StorefrontAddress::query()
                ->where('account_id', $account->id)
                ->orderByDesc('is_default_shipping')
                ->orderBy('id')
                ->get()
                ->map(fn (StorefrontAddress $a) => $a->toPublic()),
        ]);
    }

    public function storeAddress(Request $request): JsonResponse
    {
        $account = $this->account($request);

        if (StorefrontAddress::query()->where('account_id', $account->id)->count() >= self::MAX_ADDRESSES) {
            return response()->json(['message' => 'Address limit reached.', 'code' => 'address_limit'], 422);
        }

        $validated = $this->validateAddress($request);

        return response()->json([
            'data' => $this->saveAddress($account, $validated)->toPublic(),
        ], 201);
    }

    public function updateAddress(Request $request, int $address): JsonResponse
    {
        $account = $this->account($request);

        $model = StorefrontAddress::query()
            ->where('account_id', $account->id)
            ->where('id', $address)
            ->firstOrFail();

        $validated = $this->validateAddress($request, updating: true);

        return response()->json(['data' => $this->saveAddress($account, $validated, $model)->toPublic()]);
    }

    public function destroyAddress(Request $request, int $address): JsonResponse
    {
        $account = $this->account($request);

        $model = StorefrontAddress::query()
            ->where('account_id', $account->id)
            ->where('id', $address)
            ->firstOrFail();

        $model->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    // ─── Profile / security (FR-DASH-04, FR-ID-03/04/06) ───────────────

    public function updateProfile(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'locale' => ['nullable', 'string', 'max:12'],
            'marketing_email_opt_in' => ['sometimes', 'boolean'],
            'marketing_sms_opt_in' => ['sometimes', 'boolean'],
            'marketing_whatsapp_opt_in' => ['sometimes', 'boolean'],
        ]);

        $account->update($validated);

        return response()->json(['data' => ['updated' => true]]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:4096'],
            'password' => ['required', 'string', 'min:8', 'max:4096', 'confirmed'],
        ]);

        if (! \Illuminate\Support\Facades\Hash::check($validated['current_password'], $account->getRawOriginal('password'))) {
            return response()->json(['message' => 'Current password is incorrect.', 'code' => 'invalid_current_password'], 422);
        }

        $account->update(['password' => $validated['password']]);

        // Changing the password revokes every session (incl. this one) and
        // re-issues a fresh session for the actor (FR-ID-06 posture).
        $this->accounts->revokeAllSessions($account);
        $auth = $this->accounts->createSession($account, $request->ip(), $request->userAgent());

        return $this->setAuthCookies(
            response()->json(['data' => ['rotated' => true]]),
            $auth,
            $request,
        );
    }

    public function sessions(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $current = $request->attributes->get('storefront_session');

        return response()->json([
            'data' => StorefrontSession::query()
                ->where('storefront_account_id', $account->id)
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->get()
                ->map(fn (StorefrontSession $s) => [
                    'id' => $s->id,
                    'created_at' => optional($s->created_at)->toIso8601String(),
                    'last_used_at' => optional($s->last_used_at)->toIso8601String(),
                    'expires_at' => optional($s->expires_at)->toIso8601String(),
                    'device' => $s->device,
                    'current' => $current !== null && (int) $current->id === (int) $s->id,
                ]),
        ]);
    }

    public function revokeSession(Request $request, int $session): JsonResponse
    {
        $account = $this->account($request);
        $current = $request->attributes->get('storefront_session');

        if ($current !== null && (int) $current->id === $session) {
            return response()->json([
                'message' => 'Use logout to end the current session.',
                'code' => 'cannot_revoke_current',
            ], 422);
        }

        $model = StorefrontSession::query()
            ->where('storefront_account_id', $account->id)
            ->where('id', $session)
            ->firstOrFail();

        $model->forceFill(['revoked_at' => now()])->save();

        return response()->json(['data' => ['revoked' => true]]);
    }

    // ─── Internals ──────────────────────────────────────────────────────

    private function account(Request $request): StorefrontAccount
    {
        $account = $request->attributes->get('storefront_account');
        abort_if($account === null, 401);

        return $account;
    }

    private function ownedOrder(Request $request, int $orderId): StorefrontOrder
    {
        $account = $this->account($request);

        return StorefrontOrder::query()
            ->where('account_id', $account->id)
            ->where('id', $orderId)
            ->with(['items', 'statusHistory'])
            ->firstOrFail();
    }

    /**
     * @return array<string,mixed>
     */
    private function validateAddress(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:160'],
            'company' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'address1' => [$req, 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'city' => [$req, 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => [$req, 'string', 'max:20'],
            'country_code' => [$req, 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            'is_default_billing' => ['sometimes', 'boolean'],
            'is_default_shipping' => ['sometimes', 'boolean'],
        ]);
    }

    private function saveAddress(StorefrontAccount $account, array $validated, ?StorefrontAddress $existing = null): StorefrontAddress
    {
        if (isset($validated['country_code'])) {
            $validated['country_code'] = strtoupper($validated['country_code']);
        }

        return \Illuminate\Support\Facades\DB::transaction(function () use ($account, $validated, $existing) {
            $model = $existing !== null
                ? tap($existing)->update($validated)
                : StorefrontAddress::create(array_merge($validated, ['account_id' => $account->id]));

            // Exactly one default of each kind.
            foreach (['is_default_billing', 'is_default_shipping'] as $flag) {
                if (! empty($validated[$flag])) {
                    StorefrontAddress::query()
                        ->where('account_id', $account->id)
                        ->whereKeyNot($model->id)
                        ->where($flag, true)
                        ->update([$flag => false]);
                }
            }

            // First address in the book becomes both defaults.
            if ($existing === null) {
                $first = StorefrontAddress::query()->where('account_id', $account->id)->count() === 1;
                if ($first && empty($validated['is_default_billing']) && empty($validated['is_default_shipping'])) {
                    $model->update(['is_default_billing' => true, 'is_default_shipping' => true]);
                }
            }

            return $model->refresh();
        });
    }
}
