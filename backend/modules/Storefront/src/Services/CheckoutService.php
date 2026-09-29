<?php

namespace Modules\Storefront\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Services\CanonicalCustomerService;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Services\InventoryService;
use Modules\Inventory\Services\StockReservationService;
use Modules\Storefront\Enums\PaymentStatus;
use Modules\Storefront\Exceptions\CheckoutException;
use Modules\Storefront\Models\StorefrontAccount;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontOrderItem;
use Modules\Storefront\Models\StorefrontSettings;

/**
 * Idempotent storefront order placement (FR-CO-05/06, A-12/A-13, FR-ORD-03):
 *
 *  - THE single writer that converts a cart into an order. Totals/taxes come
 *    exclusively from CartService::quote() (canonical pricing + tax services)
 *    — request payloads can only ever choose product/variant/quantity and
 *    contact/address data (FR-CO-03).
 *  - Idempotency key is enforced by the DB partial unique index AND a
 *    pre-check replay; a retried submit returns the existing order without a
 *    second token, second reservation set, or second order.
 *  - Stock reservations go through the platform StockReservationService
 *    (create → allocate → release/expire lifecycle, counter-synced) — no
 *    parallel reservation math here (SOP §4). Policies: when the tenant
 *    allows backorders, unfillable tracked lines are placed without a
 *    reservation; otherwise an unfillable line aborts the whole checkout
 *    atomically.
 *  - Guests get a non-guessable one-time confirmation token (SHA-256 at
 *    rest, FR-CO-10); account orders are linked to account + CRM customer,
 *    customer created through CanonicalCustomerService (one identity).
 *  - Legal entity stamped server-side via OrderService::resolveLegalEntityId
 *    (never from the client).
 */
final class CheckoutService
{
    public const RESERVATION_TTL_MINUTES = 30;

    public const CONFIRMATION_TOKEN_BYTES = 16;

    public function __construct(
        private readonly CartService $carts,
        private readonly OrderService $orders,
        private readonly InventoryService $inventory,
        private readonly StockReservationService $reservations,
        private readonly CanonicalCustomerService $customers,
    ) {}

    /**
     * @param  array{email:string, phone?:?string}  $contact
     * @param  array<string,mixed>  $shipping
     * @param  array<string,mixed>|null  $billing
     * @return array{order: StorefrontOrder, confirmation_token: ?string, replay: bool}
     */
    public function place(
        StorefrontCart $cart,
        array $contact,
        array $shipping,
        ?array $billing,
        ?StorefrontAccount $account,
        string $idempotencyKey,
    ): array {
        $settings = StorefrontSettings::current();

        // ── Idempotent replay (A-12): exact key returns the original order ──
        $existing = StorefrontOrder::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return ['order' => $existing, 'confirmation_token' => null, 'replay' => true];
        }

        if ($account === null && ! $settings->guest_checkout) {
            throw new CheckoutException('guest_checkout_disabled', 'Guest checkout is disabled for this store.', 422);
        }

        $quote = $this->carts->quote($cart, $account?->customer_id);
        if ($quote['item_count'] <= 0) {
            throw new CheckoutException('cart_empty', 'Your cart is empty.', 422);
        }

        $email = mb_strtolower(trim($contact['email']));
        $customer = $this->resolveCustomer($account, $email, $contact, $quote);
        $legalEntityId = $this->orders->resolveLegalEntityId($customer);
        $currency = $quote['currency'] ?? $settings->currency_code;

        return DB::transaction(function () use ($cart, $quote, $contact, $shipping, $billing, $account, $customer, $legalEntityId, $idempotencyKey, $currency, $settings) {
            $plainToken = bin2hex(random_bytes(self::CONFIRMATION_TOKEN_BYTES));

            $order = StorefrontOrder::create([
                'order_number' => $this->orders->generateOrderNumber(),
                'account_id' => $account?->id,
                'customer_id' => $customer?->id,
                'legal_entity_id' => $legalEntityId,
                'email' => mb_substr($contact['email'], 0, 255),
                'phone' => isset($contact['phone']) ? mb_substr((string) $contact['phone'], 0, 40) : null,
                'status' => \Modules\Storefront\Enums\OrderStatus::PENDING->value,
                'payment_status' => PaymentStatus::PENDING_PAYMENT->value,
                'subtotal' => $quote['totals']['subtotal'],
                'discount_amount' => 0,
                'shipping_amount' => 0, // rate shopping lands with the shipping step (P2.2)
                'tax_amount' => $quote['totals']['tax'],
                'total' => $quote['totals']['total'],
                'currency' => $currency,
                'shipping_address' => $this->normaliseAddress($shipping),
                'billing_address' => $this->normaliseAddress($billing ?? $shipping),
                'shipping_method' => ['method' => 'standard', 'amount' => '0.00'],
                'tax_breakdown' => $quote['tax_breakdown'],
                'channel' => 'storefront',
                'idempotency_key' => $idempotencyKey,
                'guest_token_hash' => hash('sha256', $plainToken),
                'placed_at' => now(),
            ]);

            $lines = $this->createItems($order, $quote);
            $this->reserveStock($order, $lines, $settings);

            // Consume the placed cart; the next visit gets a fresh one.
            $cart->update(['status' => 'ordered']);

            return ['order' => $order, 'confirmation_token' => $plainToken, 'replay' => false];
        });
    }

    /**
     * Guest (or account) order lookup by one-time confirmation token (A-15
     * survives refresh; token never stored in plaintext).
     */
    public function resolveByConfirmationToken(string $plainToken): ?StorefrontOrder
    {
        if (preg_match('/^[a-f0-9]{32,64}$/', $plainToken) !== 1) {
            return null;
        }

        return StorefrontOrder::query()
            ->with(['items', 'statusHistory'])
            ->where('guest_token_hash', hash('sha256', $plainToken))
            ->first();
    }

    // ─── Internals ──────────────────────────────────────────────────────

    private function resolveCustomer(?StorefrontAccount $account, string $email, array $contact, array $quote): ?Customer
    {
        if ($account !== null && $account->customer_id !== null) {
            return Customer::query()->find($account->customer_id);
        }

        $customer = Customer::query()->whereRaw('lower(email) = ?', [$email])->first();

        if ($customer === null) {
            $name = trim(($account?->first_name ?? '').' '.($account?->last_name ?? ''));
            $customer = $this->customers->create([
                'name' => $name !== '' ? $name : ($contact['email'] ?? $email),
                'email' => $email,
                'phone' => $contact['phone'] ?? null,
                'is_active' => true,
            ]);
        }

        if ($account !== null && $account->customer_id === null) {
            $account->update(['customer_id' => $customer->id]);
        }

        return $customer;
    }

    /**
     * @param  array<string,mixed>  $quote
     * @return array<int,StorefrontOrderItem>
     */
    private function createItems(StorefrontOrder $order, array $quote): array
    {
        $productIds = collect($quote['items'])->pluck('product_id')->unique()->all();
        $products = Product::query()
            ->whereIn('id', $productIds ?: [0])
            ->get(['id', 'income_account_id', 'track_inventory', 'stock_quantity'])
            ->keyBy('id');

        $lines = [];
        foreach ($quote['items'] as $item) {
            $product = $products->get($item['product_id']);

            $lines[] = StorefrontOrderItem::create([
                'order_id' => $order->id,
                'product_id' => $item['product_id'],
                'variant_id' => $item['variant_id'],
                'name' => $item['name'],
                'sku' => $item['sku'],
                'unit_price' => $item['unit_price'],
                'quantity' => $item['quantity'],
                'tax_rate' => $item['tax_rate'],
                'tax_amount' => $item['tax_amount'],
                'discount_amount' => 0,
                'line_total' => $item['line_total'],
                'income_account_id' => $product?->income_account_id,
            ]);
        }

        return $lines;
    }

    /**
     * @param  array<int,StorefrontOrderItem>  $lines
     */
    private function reserveStock(StorefrontOrder $order, array $lines, StorefrontSettings $settings): void
    {
        $tracked = [];
        $candidates = null;

        foreach ($lines as $line) {
            $product = Product::query()->find($line->product_id, ['id', 'track_inventory', 'name']);

            if ($product === null || ! $product->track_inventory) {
                continue;
            }

            $availability = $this->inventory->checkAvailability((int) $product->id, (int) ceil((float) $line->quantity));
            $eligible = collect($availability['warehouses'] ?? [])
                ->filter(fn (array $w) => (int) ($w['available'] ?? 0) >= (int) ceil((float) $line->quantity))
                ->pluck('warehouse_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if ($eligible->isEmpty()) {
                if ($settings->allow_backorder) {
                    continue; // tenant policy: sell beyond on-hand; no reservation
                }

                throw new CheckoutException('insufficient_stock', 'Not enough stock to complete your order.', 409, [
                    'product' => $product->name,
                ]);
            }

            $tracked[] = ['line' => $line, 'product_id' => (int) $product->id, 'quantity' => (int) ceil((float) $line->quantity)];
            $candidates = $candidates === null
                ? $eligible->all()
                : array_values(array_intersect($candidates, $eligible->all()));
        }

        if ($tracked === []) {
            return;
        }

        // Prefer ONE warehouse able to fulfil every tracked line (legacy
        // precedent); if none, reserve each line at its own first eligible
        // warehouse and stamp the order with the first line's choice.
        foreach ($tracked as $index => $entry) {
            $warehouseId = null;
            if (! empty($candidates)) {
                $warehouseId = $candidates[0];
            } else {
                $availability = $this->inventory->checkAvailability($entry['product_id'], $entry['quantity']);
                $first = collect($availability['warehouses'] ?? [])
                    ->first(fn (array $w) => (int) ($w['available'] ?? 0) >= $entry['quantity']);
                $warehouseId = $first['warehouse_id'] ?? null;
            }

            if ($warehouseId === null) {
                if ($settings->allow_backorder) {
                    continue;
                }
                throw new CheckoutException('insufficient_stock', 'No warehouse can fulfil your order.', 409);
            }

            if ($index === 0) {
                $order->forceFill(['fulfillment_warehouse_id' => $warehouseId])->save();
            }

            $this->reservations->reserve(
                productId: $entry['product_id'],
                warehouseId: (int) $warehouseId,
                quantity: $entry['quantity'],
                sourceDocumentType: 'storefront_order',
                sourceDocumentId: (int) $order->id,
                sourceDocumentLineId: (int) $entry['line']->id,
                expiresAt: now()->addMinutes(self::RESERVATION_TTL_MINUTES),
                notes: 'storefront checkout',
            );
        }
    }

    /**
     * @param  array<string,mixed>  $address
     * @return array<string,string|null>
     */
    private function normaliseAddress(array $address): array
    {
        $keys = ['name', 'company', 'phone', 'address1', 'address2', 'city', 'state', 'postal_code', 'country_code'];
        $out = [];
        foreach ($keys as $key) {
            $value = $address[$key] ?? null;
            $out[$key] = $value === null || $value === '' ? null : mb_substr(trim((string) $value), 0, 255);
        }
        if (isset($out['country_code'])) {
            $out['country_code'] = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $out['country_code']) ?? '', 0, 2));
        }

        return $out;
    }
}
