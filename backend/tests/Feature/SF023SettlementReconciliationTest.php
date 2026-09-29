<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\Product;
use App\Models\User;
use App\SuperAdmin\Application\CatalogService;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Application\ModuleCatalogSyncService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Database\Seeders\ModuleFeatureSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentSettlement;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;
use Modules\Storefront\Enums\PaymentStatus;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\CartService;
use Tests\TenantTestCase;

/**
 * SF-023 — P3 FR-PAY-06 / P-04a: gateway receipts clear through Gateway
 * Receivable, and settlement files reconcile clearing -> cash with fees.
 *
 * Invariants pinned here (real HTTP + real GL):
 *  - a gateway completion posts its receipt to gateway_receivable WHEN the
 *    tenant assigned the account (zero-config tenants keep the historic
 *    cash shape — documented migration fallback, SF015);
 *  - settlement ingest books DR cash(net) + DR fee / CR receivable(gross),
 *    exactly once per transaction (DB-unique + engine journal link),
 *    classifies variance/orphan/offline/unconfigured rows and never posts
 *    on them;
 *  - refunds of UNSETTLED gateway money pay out of the receivable, of
 *    SETTLED money out of cash (no fake till movements);
 *  - the ingest route is gated by accounting.write.
 */
class SF023SettlementReconciliationTest extends TenantTestCase
{
    private string $tenantId;

    private \App\Models\ChartOfAccount $receivable;

    private \App\Models\User $accountant;

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
            'name' => 'SF023 Shop', 'email' => 'sf023-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);

        foreach (['storefront', 'payment_gateway'] as $key) {
            $item = CatalogItem::where('key', $key)->firstOrFail();
            TenantEntitlement::query()->create([
                'tenant_id' => $this->tenantId, 'catalog_item_id' => $item->id,
                'is_entitled' => true, 'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE, 'resolved_at' => now(),
            ]);
        }
        app(FeatureGateService::class)->invalidate($this->tenantId);

        StorefrontSettings::current();

        $suffix = uniqid();
        $cash = ChartOfAccount::factory()->create(['code' => "1000-{$suffix}", 'type' => 'asset', 'is_active' => true]);
        $this->receivable = ChartOfAccount::factory()->create(['code' => "1050-{$suffix}", 'type' => 'asset', 'is_active' => true]);
        $fee = ChartOfAccount::factory()->create(['code' => "5900-{$suffix}", 'type' => 'expense', 'is_active' => true]);

        // Legacy explicit mappings (the resolver probes these too): cash +
        // fee always mapped; receivable mapped only in "configured" tests.
        AccountMapping::query()->updateOrCreate(['mapping_key' => 'cash'], ['account_id' => $cash->id, 'is_active' => true]);
        AccountMapping::query()->updateOrCreate(['mapping_key' => 'general_expense'], ['account_id' => $fee->id, 'is_active' => true]);

        $owner = User::factory()->create();
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

        $this->accountant = $this->makeStaff('accountant', 'Accountant'); // holds accounting.write
    }

    public function test_gateway_sale_receipt_lands_on_gateway_receivable_when_configured(): void
    {
        $this->configureClearing();
        [$order, $transaction] = $this->gatewayPaidOrder(60.0);

        $je = $this->saleEntry($order);
        $this->assertTrue(
            $je->lines()->where('account_id', $this->receivable->id)->where('debit', '>', 0)->exists(),
            'gateway money is a receivable until settled (P-04a)'
        );
        $this->assertSame(
            (float) $je->lines()->sum('debit'),
            (float) $je->lines()->sum('credit'),
            'combined entry stays balanced'
        );
        $this->assertSame(PaymentStatus::PAID->value, $order->fresh()->payment_status);
    }

    public function test_ingest_reconciles_matched_rows_and_classifies_the_rest(): void
    {
        $this->configureClearing();
        [$order, $transaction] = $this->gatewayPaidOrder(50.0);
        [, $other] = $this->gatewayPaidOrder(30.0);

        $csv = implode("\n", [
            'transaction_id,gross,fee,net,currency,settled_at',
            "{$transaction->transaction_id},50.00,2.00,48.00,SGD,2026-09-29",
            'pi_never_seen,10.00,0.50,9.50,SGD,2026-09-29',
            "{$other->transaction_id},29.00,1.00,28.00,SGD,2026-09-29", // variance: gross != captured 30
        ]);

        $res = $this->actingAs($this->accountant)->post('/api/v1/payment-settlements/ingest', [
            'file' => UploadedFile::fake()->createWithContent('settlement.csv', $csv),
            'batch_reference' => 'HP-2026-09-28',
        ], ['Accept' => 'application/json']);

        $res->assertStatus(200)
            ->assertJsonPath('data.summary.matched', 1)
            ->assertJsonPath('data.summary.orphan', 1)
            ->assertJsonPath('data.summary.variance', 1);

        $settlement = PaymentSettlement::query()->sole();
        $this->assertSame((int) $transaction->id, (int) $settlement->payment_transaction_id);
        $this->assertSame('48.00', (string) $settlement->net_amount);
        $this->assertNotNull($settlement->journal_entry_id);

        $je = JournalEntry::query()->with('lines')->find($settlement->journal_entry_id);
        $this->assertSame(48.0, round((float) $je->lines()->where('account_id', $this->account('cash'))->sum('debit'), 2));
        $this->assertSame(2.0, round((float) $je->lines()->where('account_id', $this->account('general_expense'))->sum('debit'), 2));
        $this->assertSame(50.0, round((float) $je->lines()->where('account_id', $this->receivable->id)->sum('credit'), 2));
        $this->assertSame((float) $je->lines()->sum('debit'), (float) $je->lines()->sum('credit'));

        // replay the same file: nothing posts twice
        $again = $this->actingAs($this->accountant)->post('/api/v1/payment-settlements/ingest', [
            'file' => UploadedFile::fake()->createWithContent('settlement.csv', $csv),
            'batch_reference' => 'HP-2026-09-28',
        ], ['Accept' => 'application/json']);
        $again->assertStatus(200)->assertJsonPath('data.summary.already_settled', 1);
        $this->assertSame(1, PaymentSettlement::query()->count());
        $this->assertSame(1, JournalEntryLink::query()->where('source_document_type', 'payment_settlement')->count());

        // the variance row never became a settlement
        $this->assertFalse(PaymentSettlement::query()->where('payment_transaction_id', $other->id)->exists());
    }

    public function test_refunds_track_where_the_money_actually_is(): void
    {
        $this->configureClearing();
        $manager = $this->makeStaff('manager', 'Manager');

        // unsettled gateway money: the refund claims the receivable
        [$orderA, $txnA] = $this->gatewayPaidOrder(40.0);
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$orderA->id}/refund", [
            'amount' => 10, 'reason' => 'before payout',
        ])->assertStatus(200);
        $this->assertRefundPayoutAccount($orderA, $this->receivable->id);

        // settled money: the refund pays out of cash
        [$orderB, $txnB] = $this->gatewayPaidOrder(40.0);
        $csv = implode("\n", [
            'transaction_id,gross,fee,net,currency',
            "{$txnB->transaction_id},40.00,1.00,39.00,SGD",
        ]);
        $this->actingAs($this->accountant)->post('/api/v1/payment-settlements/ingest', [
            'file' => UploadedFile::fake()->createWithContent('s.csv', $csv),
        ], ['Accept' => 'application/json'])->assertStatus(200);

        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$orderB->id}/refund", [
            'amount' => 10, 'reason' => 'after payout',
        ])->assertStatus(200);
        $this->assertRefundPayoutAccount($orderB, $this->account('cash'));
    }

    public function test_unconfigured_clearing_falls_back_and_refuses_settlements_without_it(): void
    {
        // no gateway_receivable mapping: sale posts to cash (SF015 shape)...
        [$order, $transaction] = $this->gatewayPaidOrder(24.0);
        $je = $this->saleEntry($order);
        $this->assertTrue($je->lines()->where('account_id', $this->account('cash'))->where('debit', '>', 0)->exists());

        // ...and a settlement row cannot be booked (credit leg has no home)
        $csv = "transaction_id,gross,fee,net\n{$transaction->transaction_id},24.00,1.00,23.00";
        $this->actingAs($this->accountant)->post('/api/v1/payment-settlements/ingest', [
            'file' => UploadedFile::fake()->createWithContent('s.csv', $csv),
        ], ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJsonPath('data.summary.clearing_unconfigured', 1);

        $this->assertSame(0, PaymentSettlement::query()->count());
        $this->assertSame(0, JournalEntryLink::query()->where('source_document_type', 'payment_settlement')->count());
    }

    public function test_offline_anchor_rows_are_not_gateway_settlements_and_viewer_is_denied(): void
    {
        $this->configureClearing();

        // internal offline anchor transaction (SF022 shape)
        $offlineGateway = PaymentGateway::create(['gateway_name' => 'offline2', 'is_active' => false]);
        $txn = PaymentTransaction::create([
            'payment_gateway_id' => $offlineGateway->id,
            'transaction_id' => 'offline:9:proof:9',
            'amount' => 10.00, 'currency' => 'SGD', 'status' => 'completed',
            'metadata' => ['type' => 'storefront_order', 'order_id' => 9],
        ]);
        $csv = "transaction_id,gross\n{$txn->transaction_id},10.00";

        // viewer lacks accounting.write
        $viewer = $this->makeStaff('viewer', 'Viewer');
        $this->actingAs($viewer)->post('/api/v1/payment-settlements/ingest', [
            'file' => UploadedFile::fake()->createWithContent('s.csv', $csv),
        ], ['Accept' => 'application/json'])->assertStatus(403);

        $this->actingAs($this->accountant)->post('/api/v1/payment-settlements/ingest', [
            'file' => UploadedFile::fake()->createWithContent('s.csv', $csv),
        ], ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJsonPath('data.summary.offline', 1);
    }

    // ─── fixtures / helpers ───────────────────────────────────────────

    private function configureClearing(): void
    {
        AccountMapping::query()->updateOrCreate(
            ['mapping_key' => 'gateway_receivable'],
            ['account_id' => $this->receivable->id, 'is_active' => true]
        );
    }

    private function account(string $key): int
    {
        return (int) AccountMapping::query()->where('mapping_key', $key)->whereNull('document_type')->value('account_id');
    }

    private function saleEntry(StorefrontOrder $order): JournalEntry
    {
        $link = JournalEntryLink::query()
            ->where('source_document_type', 'storefront_order')
            ->where('source_document_id', (int) $order->id)
            ->where('relation_type', 'post')
            ->firstOrFail();

        return JournalEntry::query()->with('lines')->findOrFail($link->journal_entry_id);
    }

    private function assertRefundPayoutAccount(StorefrontOrder $order, int $accountId): void
    {
        $link = JournalEntryLink::query()
            ->where('source_document_type', 'storefront_refund')
            ->whereHas('journalEntry', fn ($q) => $q->where('reference', 'like', 'REFUND-'.$order->order_number.'-%'))
            ->firstOrFail();
        $je = JournalEntry::query()->with('lines')->findOrFail($link->journal_entry_id);
        $this->assertTrue(
            $je->lines()->where('account_id', $accountId)->where('credit', '>', 0)->exists(),
            'refund credit leg must sit where the money physically is'
        );
    }

    /** @return array{0: StorefrontOrder, 1: PaymentTransaction} */
    private function gatewayPaidOrder(float $price): array
    {
        $gateway = PaymentGateway::query()->where('gateway_name', 'hitpay')->first()
            ?? PaymentGateway::create(['gateway_name' => 'hitpay', 'is_active' => true, 'is_test_mode' => true]);

        $product = $this->makeProduct(['price' => $price]);
        $guestToken = bin2hex(random_bytes(32));
        $cart = StorefrontCart::create(['guest_token_hash' => hash('sha256', $guestToken), 'status' => 'active']);
        app(CartService::class)->addOrUpdateItem($cart, $product->id, null, 1);

        $res = $this->withUnencryptedCookie(CartService::COOKIE, $guestToken)->postJson('/api/v1/storefront/checkout', [
            'email' => 'sf023-'.Str::random(6).'@example.test',
            'idempotency_key' => 'sf023-'.Str::random(24),
            'shipping_address' => ['name' => 'S T', 'address1' => '23 Rd', 'city' => 'Town', 'postal_code' => '54321', 'country_code' => 'SG'],
        ]);
        $res->assertStatus(201);
        $order = StorefrontOrder::query()->findOrFail((int) $res->json('data.id'));

        $transaction = PaymentTransaction::create([
            'payment_gateway_id' => $gateway->id,
            'transaction_id' => 'sfpay_'.Str::random(12),
            'amount' => $price,
            'currency' => 'SGD',
            'status' => 'completed',
            'metadata' => ['type' => 'storefront_order', 'order_id' => (int) $order->id],
        ]);
        app(PaymentTransactionCompletionService::class)->complete($transaction, 'card');

        return [$order->fresh(), $transaction->fresh()];
    }

    private function makeProduct(array $overrides = []): Product
    {
        $name = $overrides['name'] ?? 'SF023 Product '.Str::random(4);

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

    private function makeStaff(string $centralRole, string $spatieRole): User
    {
        $user = User::factory()->create(['name' => $spatieRole]);
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
