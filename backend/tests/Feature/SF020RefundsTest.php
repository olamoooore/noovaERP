<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RefundTransaction;
use App\Models\StockMovement;
use App\Services\PaymentRefundService;
use App\SuperAdmin\Application\CatalogService;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Application\ModuleCatalogSyncService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Database\Seeders\ModuleFeatureSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationMessage;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;
use Modules\Storefront\Enums\OrderStatus;
use Modules\Storefront\Enums\PaymentStatus;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\CartService;
use Tests\TenantTestCase;

/**
 * SF-020 — P3 refunds (FR-PAY-05, PAY-R10, A-18 platform direction).
 *
 * Real HTTP through the admin console onto a canonically completed order;
 * ledger verified against the engine's own journal links. The mechanism is
 * the shared PaymentRefundService — these tests pin the STOREFRONT shape:
 * one cash-based reversal per refund (DR revenue/tax, CR cash — no AR),
 * no stock movement (returns are P4), machine-only state, notification with
 * the seeded template, replay convergence, and the fail-closed guard that
 * stops storefront transactions from taking the generic AR-shaped path.
 */
class SF020RefundsTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        Queue::fake();

        DB::statement('SET search_path TO public');
        $this->bindTenantContext((string) Str::uuid());
        $this->seed(RolePermissionSeeder::class);

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF020 Shop', 'email' => 'sf020-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);

        $item = CatalogItem::where('key', 'storefront')->firstOrFail();
        TenantEntitlement::query()->create([
            'tenant_id' => $this->tenantId, 'catalog_item_id' => $item->id,
            'is_entitled' => true, 'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE, 'resolved_at' => now(),
        ]);
        app(FeatureGateService::class)->invalidate($this->tenantId);

        StorefrontSettings::current();

        // Posting authority actor (same precedent as SF015): completion and
        // refund posting attribute to the first tenant user — give the tenant
        // an OWNER member before any flow runs.
        $owner = \App\Models\User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $owner->email],
            ['name' => $owner->name, 'password' => $owner->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $owner->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => 'owner', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $owner->assignRole('Owner');
        $this->bindTenantContext($this->tenantId);

        $this->artisan('storefront:templates:seed')->assertSuccessful();
    }

    public function test_full_refund_posts_one_reversal_and_refunds_end_to_end(): void
    {
        $manager = $this->makeStaff('manager', 'Manager');
        [$order, $transaction] = $this->paidOrder(30.0);
        $invoice = \App\Models\Invoice::query()->findOrFail($order->invoice_id);
        $customer = Customer::query()->findOrFail($invoice->customer_id);
        $salesMovements = StockMovement::query()->where('reference', $order->order_number)->count();

        $res = $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/refund", [
            'reason' => 'damaged on arrival',
        ]);
        $res->assertStatus(200)
            ->assertJsonPath('data.status', OrderStatus::REFUNDED->value)
            ->assertJsonPath('data.payment_status', PaymentStatus::REFUNDED->value)
            ->assertJsonPath('data.refund.status', 'completed');

        // exactly one storefront refund journal entry, cash-shaped
        $links = JournalEntryLink::query()->where('source_document_type', 'storefront_refund')->get();
        $this->assertCount(1, $links, 'A refund posts exactly one reversal (A-18).');
        $je = JournalEntry::query()->with('lines.account')->find($links[0]->journal_entry_id);
        $this->assertNotNull($je);
        $sumDebit = (float) $je->lines->sum('debit');
        $sumCredit = (float) $je->lines->sum('credit');
        $this->assertSame(30.0, round($sumCredit, 2), 'credit side is the cash paid out');
        $this->assertSame(30.0, round($sumDebit, 2), 'balanced entry');
        $arCredits = (float) $je->lines->sum(function ($l) {
            return stripos((string) ($l->account->name ?? ''), 'receivable') !== false ? (float) $l->credit : 0.0;
        });
        $this->assertSame(0.0, $arCredits, 'storefront refunds must NOT touch accounts receivable (cash-based sale)');

        // transaction + invoice + customer bookkeeping via the shared service
        $freshTxn = $transaction->fresh();
        $this->assertSame(30.0, round((float) $freshTxn->refunded_amount, 2));
        $this->assertSame('refunded', $freshTxn->status);
        $invoice->refresh();
        $this->assertSame(0.0, round((float) $invoice->paid_amount, 2), 'refund reopens the invoice settlement');
        $this->assertSame(1, Payment::query()->where('method', 'refund')->count(), 'one negative refund payment');
        $refundPayment = Payment::query()->where('method', 'refund')->sole();
        $this->assertSame(-30.0, round((float) $refundPayment->amount, 2));
        $this->assertSame($je->id, (int) $refundPayment->journal_entry_id, 'refund payment links the module reversal');
        $this->assertSame(1, RefundTransaction::query()->where('status', 'completed')->count());

        // NO physical reversal in P3 — returns are the P4 layer (FR-ORD-06)
        $this->assertSame($salesMovements, StockMovement::query()->where('reference', $order->order_number)->count(),
            'refunds move money only; stock movements stay exactly as the sale left them');

        // machine-only audit trail
        $this->assertSame('staff', $order->fresh()->statusHistory()->latest('id')->first()->actor_type);

        // lifecycle mail through the seeded template (FR-MSG-03)
        $msg = CommunicationMessage::query()->where('subtype', 'storefront.order.refunded')->sole();
        $this->assertSame("Refund issued for order {$order->order_number}", $msg->subject);
        $this->assertStringNotContainsString('{{', $msg->body, 'template placeholders must all resolve');
        $this->assertStringContainsString('30.00', $msg->body);
        $this->assertStringContainsString('damaged on arrival', $msg->body);

        // outstanding balance reopened for the customer
        $this->assertSame(30.0, round((float) $customer->fresh()->total_outstanding, 2),
            'the refund reopens the receivable exactly by its amount');
    }

    public function test_partial_refund_keeps_the_order_open_and_each_refund_gets_its_own_entry(): void
    {
        $manager = $this->makeStaff('manager', 'Manager');
        [$order, $transaction] = $this->paidOrder(60.0);

        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/refund", [
            'amount' => 20, 'reason' => 'one item returned to stock room',
        ])->assertStatus(200)
            ->assertJsonPath('data.payment_status', PaymentStatus::PARTIALLY_REFUNDED->value)
            ->assertJsonPath('data.status', OrderStatus::PAID->value);

        $this->assertSame(20.0, round((float) $transaction->fresh()->refunded_amount, 2));

        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/refund", [
            'amount' => 15.5, 'reason' => 'shipping fee waived retroactively',
        ])->assertStatus(200)
            ->assertJsonPath('data.payment_status', PaymentStatus::PARTIALLY_REFUNDED->value);

        $this->assertSame(35.5, round((float) $transaction->fresh()->refunded_amount, 2));
        $this->assertCount(2, JournalEntryLink::query()->where('source_document_type', 'storefront_refund')->get(),
            'each distinct partial refund posts exactly one entry');
        $this->assertSame(OrderStatus::PAID->value, $order->fresh()->status, 'partial money-back does not terminalize the order');
    }

    public function test_replaying_the_same_refund_converges_without_second_effects(): void
    {
        $manager = $this->makeStaff('manager', 'Manager');
        [$order, $transaction] = $this->paidOrder(25.0);

        $key = 'sf20-replay-'.Str::uuid()->toString();
        $body = ['amount' => 10, 'reason' => 'duplicate attempt'];
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/refund", $body, [
            'Idempotency-Key' => $key,
        ])->assertStatus(200);
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/refund", $body, [
            'Idempotency-Key' => $key,
        ])->assertStatus(200);

        $this->assertSame(10.0, round((float) $transaction->fresh()->refunded_amount, 2), 'replay must not double-refund');
        $this->assertCount(1, JournalEntryLink::query()->where('source_document_type', 'storefront_refund')->get());
        $this->assertSame(1, CommunicationMessage::query()->where('subtype', 'storefront.order.refunded')->count(),
            'a replayed refund must not re-notify');
    }

    public function test_invalid_refund_requests_are_rejected_without_ledger_damage(): void
    {
        $manager = $this->makeStaff('manager', 'Manager');

        // over-refund
        [$order, $transaction] = $this->paidOrder(18.0);
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/refund", [
            'amount' => 19, 'reason' => 'greedy',
        ])->assertStatus(422)->assertJsonPath('code', 'refund_exceeds_balance');

        // unsettled order
        $pending = $this->placeOrder(12.0);
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$pending->id}/refund", [
            'reason' => 'too early',
        ])->assertStatus(422)->assertJsonPath('code', 'not_refundable');

        // paid-status order whose payment is missing (no settled transaction)
        $orphan = $this->placeOrder(11.0);
        $orphan->forceFill(['payment_status' => PaymentStatus::PAID->value, 'status' => OrderStatus::PAID->value])->save();
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$orphan->id}/refund", [
            'reason' => 'ghost payment',
        ])->assertStatus(422)->assertJsonPath('code', 'no_settled_payment');

        $this->assertSame(0, JournalEntryLink::query()->where('source_document_type', 'storefront_refund')->count());
    }

    public function test_generic_ledger_path_refuses_storefront_transactions(): void
    {
        // Fail-closed guard in the shared service: anything that reaches
        // processRefund for a storefront transaction WITHOUT the module's
        // pre-posted cash-shaped reversal must be refused, not silently
        // reversed through the AR-shaped generic entry.
        [$order, $transaction] = $this->paidOrder(21.0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('StorefrontOrderRefundHandler');

        app(PaymentRefundService::class)->processRefund($transaction->fresh(), 21.0, 'bypass attempt');
    }

    public function test_merchant_toggle_and_rbac_on_the_refund_entry(): void
    {
        $viewer = $this->makeStaff('viewer', 'Viewer');
        $manager = $this->makeStaff('manager', 'Manager');
        [$order] = $this->paidOrder(14.0);

        $this->actingAs($viewer)->postJson("/api/v1/admin/storefront/orders/{$order->id}/refund", ['reason' => 'nope'])
            ->assertStatus(403)->assertJsonPath('required_permission', 'storefront.write');

        $settings = StorefrontSettings::current();
        $settings->update(['notification_settings' => ['order_refunded' => ['email' => false]]]);

        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/refund", [
            'reason' => 'merchant opted out of refund mail',
        ])->assertStatus(200);

        $this->assertSame(0, CommunicationMessage::query()->where('subtype', 'storefront.order.refunded')->count(),
            'FR-MSG-06 toggle must suppress the lifecycle mail');
        $this->assertCount(1, JournalEntryLink::query()->where('source_document_type', 'storefront_refund')->get(),
            'the ledger side is independent of the merchant toggle');
    }

    // ─── fixtures ─────────────────────────────────────────────────────

    /** @return array{0: StorefrontOrder, 1: PaymentTransaction} */
    private function paidOrder(float $price, ?string $product = null): array
    {
        $product = $this->makeProduct(['price' => $price, 'name' => $product ?? 'SF20 Item '.Str::random(4)]);
        [$guestToken] = $this->cartWith($product->id, 1);
        $res = $this->checkout($guestToken);
        $this->assertSame(201, $res->status(), $res->getContent());
        $order = StorefrontOrder::query()->findOrFail((int) $res->json('data.id'));

        $transaction = $this->makeTransaction($order, $price);
        // Production callers (webhook/confirm-payment) mark the transaction
        // completed BEFORE running the canonical completion; mirror that.
        $transaction->update(['status' => 'completed']);
        app(PaymentTransactionCompletionService::class)->complete($transaction, 'card');

        return [$order->fresh(), $transaction->fresh()];
    }

    private function placeOrder(float $price): StorefrontOrder
    {
        $product = $this->makeProduct(['price' => $price]);
        [$guestToken] = $this->cartWith($product->id, 1);
        $res = $this->checkout($guestToken);
        $this->assertSame(201, $res->status(), $res->getContent());

        return StorefrontOrder::query()->findOrFail((int) $res->json('data.id'));
    }

    private function makeTransaction(StorefrontOrder $order, float $amount): PaymentTransaction
    {
        $gateway = PaymentGateway::query()->where('gateway_name', 'hitpay')->first()
            ?? PaymentGateway::create(['gateway_name' => 'hitpay', 'is_active' => true, 'is_test_mode' => true]);

        return PaymentTransaction::create([
            'payment_gateway_id' => $gateway->id,
            'transaction_id' => 'sfpay_'.Str::random(12),
            'amount' => $amount,
            'currency' => $order->currency ?? 'SGD',
            'status' => 'pending',
            'metadata' => ['type' => 'storefront_order', 'order_id' => (int) $order->id],
        ]);
    }

    private function cartWith(int $productId, int $qty): array
    {
        $token = bin2hex(random_bytes(32));
        $cart = StorefrontCart::create(['guest_token_hash' => hash('sha256', $token), 'status' => 'active']);
        app(CartService::class)->addOrUpdateItem($cart, $productId, null, $qty);

        return [$token, $cart];
    }

    private function checkout(string $guestToken)
    {
        return $this->withUnencryptedCookie(CartService::COOKIE, $guestToken)->postJson('/api/v1/storefront/checkout', [
            'email' => 'sf20-'.Str::random(6).'@example.test',
            'idempotency_key' => 'sf20-'.Str::random(24),
            'shipping_address' => ['name' => 'R N', 'address1' => '20 Rd', 'city' => 'Town', 'postal_code' => '54321', 'country_code' => 'SG'],
        ]);
    }

    private function makeProduct(array $overrides = []): Product
    {
        $name = $overrides['name'] ?? 'SF20 Product '.Str::random(4);

        return Product::factory()->create(array_merge([
            'name' => $name,
            'slug' => Str::slug($name),
            'show_on_website' => true,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
            'stock_quantity' => 100,
            'price' => 10,
            'tax_rate' => 0,
        ], $overrides));
    }

    private function makeStaff(string $centralRole, string $spatieRole): \App\Models\User
    {
        $user = \App\Models\User::factory()->create(['name' => $spatieRole]);
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => $centralRole, 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole($spatieRole);
        $this->bindTenantContext($this->tenantId);

        return $user;
    }
}
