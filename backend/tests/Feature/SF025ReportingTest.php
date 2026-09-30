<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontPromotion;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\CartService;
use Tests\TenantTestCase;

/**
 * SF-025 — FR-ADM-06 reporting & ops dashboard (P3) + P-04g.
 *
 * Every figure is asserted EXACT against a deterministic fixture (sales,
 * tax, reconciliation incl. the ledger tie, funnel, overview), CSV export
 * parity, permission gates, schema-level cross-tenant isolation, and the
 * payment-analytics PG date fix (90d/1y trends used MySQL-only functions).
 */
class SF025ReportingTest extends TenantTestCase
{
    private string $tenantId;

    private PaymentGateway $gateway;

    private ?string $coupon = 'TENOFF';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        Queue::fake();
        Storage::fake('local');

        DB::statement('SET search_path TO public');
        $this->bindTenantContext((string) Str::uuid());
        $this->seed(RolePermissionSeeder::class);

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF025 Shop', 'email' => 'sf025-'.Str::random(6).'@example.test',
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

        StorefrontSettings::current()->update(['currency_code' => 'SGD', 'payment_settings' => ['offline' => [
            'bank_transfer' => ['enabled' => true, 'label' => 'Bank transfer', 'instructions' => 'DBS 001-1'],
        ]]]);

        $suffix = uniqid();
        $receivable = ChartOfAccount::factory()->create(['code' => "1050-{$suffix}", 'type' => 'asset', 'is_active' => true]);
        AccountMapping::query()->updateOrCreate(['mapping_key' => 'gateway_receivable'], ['account_id' => $receivable->id, 'is_active' => true]);

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

        $this->gateway = PaymentGateway::create(['gateway_name' => 'hitpay', 'is_active' => true, 'is_test_mode' => true]);

        StorefrontPromotion::create(['code' => 'TENOFF', 'type' => 'percent', 'value' => 10, 'is_active' => true]);
    }

    public function test_sales_report_exact_figures_and_csv_parity(): void
    {
        $this->buildFixture();
        $admin = $this->makeStaff('admin', 'Admin');

        $res = $this->actingAs($admin)->getJson('/api/v1/admin/storefront/reports/sales')->assertStatus(200);
        $paid = collect($res->json('data.paid_by_currency'))->firstWhere('currency', 'SGD');
        $this->assertSame(3, $paid['orders']);
        $this->assertSame('150.00', $paid['subtotal']);
        $this->assertSame('5.00', $paid['discount']);
        $this->assertSame('14.50', $paid['tax']);
        $this->assertSame('159.50', $paid['gross']);

        $statuses = collect($res->json('data.orders_by_status'))->keyBy('status');
        $this->assertSame(3, $statuses['paid']['orders']);
        $this->assertSame(2, $statuses['pending']['orders']);

        $channel = collect($res->json('data.channel_capture'))->firstWhere('channel', 'hitpay');
        $this->assertSame(3, $channel['transactions']);
        $this->assertSame('159.50', $channel['captured']);

        $csv = $this->actingAs($admin)->getJson('/api/v1/admin/storefront/reports/sales?format=csv');
        $csv->assertStatus(200);
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('"159.50"', $csv->getContent());
    }

    public function test_tax_report_reflects_discounted_tax_bases(): void
    {
        $this->buildFixture();
        $admin = $this->makeStaff('admin', 'Admin');

        $res = $this->actingAs($admin)->getJson('/api/v1/admin/storefront/reports/tax')->assertStatus(200);
        $row = collect($res->json('data.by_rate'))->firstWhere('currency', 'SGD');
        $this->assertEquals(10.0, $row['tax_rate']);
        $this->assertSame('145.00', $row['base']);   // 50 + 50 + 45 (coupon line netted)
        $this->assertSame('14.50', $row['tax']);     // 5 + 5 + 4.5
    }

    public function test_reconciliation_report_with_ledger_tie(): void
    {
        $this->buildFixture();
        $admin = $this->makeStaff('admin', 'Admin');

        $res = $this->actingAs($admin)->getJson('/api/v1/admin/storefront/reports/reconciliation')->assertStatus(200);
        $this->assertSame(3, $res->json('data.captured.transactions'));
        $this->assertSame('159.50', $res->json('data.captured.amount'));
        $this->assertSame('5.00', $res->json('data.captured.refunded'));

        $this->assertSame(1, $res->json('data.settlements.settlements'));
        $this->assertSame('55.00', $res->json('data.settlements.gross'));
        $this->assertSame('2.75', $res->json('data.settlements.fees'));
        $this->assertSame('52.25', $res->json('data.settlements.net'));

        $this->assertSame(2, $res->json('data.unsettled.count'));
        $this->assertSame('99.50', $res->json('data.unsettled.amount'));

        $this->assertTrue($res->json('data.ledger_tie.balanced'));

        // the ledger tie is not decorative: break it and the report flips
        \Modules\PaymentGateway\Models\PaymentSettlement::query()->update(['journal_entry_id' => null]);
        $broken = $this->actingAs($admin)->getJson('/api/v1/admin/storefront/reports/reconciliation');
        $this->assertSame(1, $broken->json('data.ledger_tie.settlements_without_gl'));
        $this->assertFalse($broken->json('data.ledger_tie.balanced'));
    }

    public function test_funnel_and_overview_counts(): void
    {
        $this->buildFixture();
        $admin = $this->makeStaff('admin', 'Admin');

        $funnel = $this->actingAs($admin)->getJson('/api/v1/admin/storefront/reports/funnel')->assertStatus(200)->json('data');
        $this->assertSame(8, $funnel['carts']['created']);
        $this->assertSame(5, $funnel['carts']['converted_to_orders']);
        $this->assertSame(1, $funnel['carts']['abandoned']);
        $this->assertSame(2, $funnel['carts']['still_active']);
        $this->assertSame(5, $funnel['orders']['placed']);
        $this->assertSame(3, $funnel['orders']['paid']);
        $this->assertEquals(60.0, $funnel['orders']['placement_to_payment_pct']);

        $overview = $this->actingAs($admin)->getJson('/api/v1/admin/storefront/reports/overview')->assertStatus(200)->json('data');
        $rev = collect($overview['revenue_today'])->firstWhere('currency', 'SGD');
        $this->assertSame(3, $rev['orders']);
        $this->assertSame('159.50', $rev['gross']);
        $this->assertSame(2, $overview['awaiting_payment_orders']);
        $this->assertSame(1, $overview['offline_proofs_pending']);
        $this->assertSame(2, $overview['unsettled_gateway_funds']['count']);
        $this->assertSame(0, $overview['failed_payments_7d']);
        $this->assertSame(0, $overview['failed_messages_24h']);
    }

    public function test_reporting_is_tenant_scoped_at_the_schema_level(): void
    {
        $this->buildFixture();

        // A real second schema with the same tables but ZERO data: the
        // reports read exclusively through search_path, so every figure must
        // be empty. If any query leaked cross-schema (public fallback), the
        // tenant-2 report would show tenant-1 money.
        $schema = 'sf025_t2_'.strtolower(Str::random(8));
        DB::statement("CREATE SCHEMA \"{$schema}\"");
        foreach (['storefront_orders', 'storefront_order_items', 'storefront_carts', 'storefront_settings',
            'storefront_payment_proofs', 'payment_transactions', 'payment_gateways', 'payment_settlements',
            'communication_messages', 'products', 'storefront_promotions', 'storefront_promotion_redemptions',
            'account_mappings', 'chart_of_accounts'] as $table) {
            DB::statement("CREATE TABLE IF NOT EXISTS \"{$schema}\".{$table} (LIKE public.{$table} INCLUDING DEFAULTS INCLUDING INDEXES)");
        }

        DB::statement("SET search_path TO \"{$schema}\", public");
        $service = app(\Modules\Storefront\Services\StorefrontReportService::class);
        $sales = $service->sales(now()->subMonth(), now());
        $this->assertSame([], $sales['paid_by_currency']);
        $this->assertSame([], $sales['daily']);
        $funnel = $service->funnel(now()->subMonth(), now());
        $this->assertSame(0, $funnel['orders']['placed']);
        $this->assertSame(0, $funnel['carts']['created']);

        // restore the working schema (the harness represents tenant 1 in public)
        DB::statement('SET search_path TO public');
        $again = $service->sales(now()->subMonth(), now());
        $this->assertNotSame([], $again['paid_by_currency'], 'tenant-1 data untouched by the probe');
    }

    public function test_rbac_on_reporting_and_p04g_analytics_dates(): void
    {
        $this->buildFixture();
        $viewer = $this->makeStaff('viewer', 'Viewer');
        $accountant = $this->makeStaff('accountant', 'Accountant');

        $this->actingAs($viewer)->getJson('/api/v1/admin/storefront/reports/sales')->assertStatus(200);
        $this->actingAs($accountant)->getJson('/api/v1/admin/storefront/reports/sales')
            ->assertStatus(403)->assertJsonPath('required_permission', 'storefront.view');

        // P-04g: 90d/1y trends used MySQL YEARWEEK()/DATE_FORMAT() — on this
        // PostgreSQL platform they threw 500. Both periods must now answer.
        $admin = $this->makeStaff('admin', 'Admin');
        foreach (['90days', '1year'] as $period) {
            $this->actingAs($admin)->getJson('/api/v1/payment-analytics?period='.$period)
                ->assertStatus(200)
                ->assertJsonStructure(['data']);
        }
    }

    // ─── fixture: 5 orders, 1 settled, 1 refunded, 1 offline-proof, carts ──

    private function buildFixture(): void
    {
        // A: paid + settled
        $a = $this->checkoutOrder();
        $aTxn = $this->pay($a['order'], 55.00);
        $this->settle($aTxn->transaction_id, '55.00', '2.75', '52.25');

        // B: paid, unsettled
        $b = $this->checkoutOrder();
        $this->pay($b['order'], 55.00);

        // C: placed, unpaid
        $this->checkoutOrder();

        // D: coupon TENOFF → 49.50, paid, partial refund 5.00
        $d = $this->checkoutOrder($this->coupon);
        $this->pay($d['order'], 49.50);
        $manager = $this->makeStaff('manager', 'Manager');
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$d['order']->id}/refund", [
            'amount' => 5, 'reason' => 'reported reporting test',
        ])->assertStatus(200);

        // E: offline proof submitted (awaiting approval → unpaid)
        $e = $this->checkoutOrder();
        $this->post("/api/v1/storefront/orders/{$e['order']->id}/proof-of-payment", [
            'token' => $e['token'], 'method' => 'bank_transfer',
            'proof' => UploadedFile::fake()->image('r.png'),
        ])->assertStatus(200);

        // funnel filler carts: 2 bare active + 1 abandoned
        $t = bin2hex(random_bytes(32));
        StorefrontCart::create(['guest_token_hash' => hash('sha256', $t), 'status' => 'active']);
        $t2 = bin2hex(random_bytes(32));
        StorefrontCart::create(['guest_token_hash' => hash('sha256', $t2), 'status' => 'active']);
        $t3 = bin2hex(random_bytes(32));
        StorefrontCart::create(['guest_token_hash' => hash('sha256', $t3), 'status' => 'abandoned']);
    }

    /** @return array{order: StorefrontOrder, token: string} */
    private function checkoutOrder(?string $coupon = null): array
    {
        $product = Product::query()->first() ?? $this->makeProduct();
        $guestToken = bin2hex(random_bytes(32));
        $cart = StorefrontCart::create(['guest_token_hash' => hash('sha256', $guestToken), 'status' => 'active']);
        app(CartService::class)->addOrUpdateItem($cart, $product->id, null, 1);

        $res = $this->withUnencryptedCookie(CartService::COOKIE, $guestToken)->postJson('/api/v1/storefront/checkout', array_filter([
            'email' => 'sf25-'.Str::random(6).'@example.test',
            'idempotency_key' => 'sf25-'.Str::random(24),
            'coupon_code' => $coupon,
            'shipping_address' => ['name' => 'R P', 'address1' => '25 Rd', 'city' => 'Town', 'postal_code' => '54321', 'country_code' => 'SG'],
        ], fn ($v) => $v !== null));
        $res->assertStatus(201);

        return [
            'order' => StorefrontOrder::query()->findOrFail((int) $res->json('data.id')),
            'token' => (string) $res->json('data.confirmation_token'),
        ];
    }

    private function pay(StorefrontOrder $order, float $amount): PaymentTransaction
    {
        $txn = PaymentTransaction::create([
            'payment_gateway_id' => $this->gateway->id,
            'transaction_id' => 'sf25pay_'.Str::random(10),
            'amount' => $amount,
            'currency' => 'SGD',
            'status' => 'completed',
            'metadata' => ['type' => 'storefront_order', 'order_id' => (int) $order->id],
        ]);
        app(PaymentTransactionCompletionService::class)->complete($txn, 'card');

        return $txn;
    }

    private function settle(string $txnId, string $gross, string $fee, string $net): void
    {
        $admin = $this->makeStaff('admin', 'Admin');
        $csv = "transaction_id,gross,fee,net,currency\n{$txnId},{$gross},{$fee},{$net},SGD";
        $this->actingAs($admin)->post('/api/v1/payment-settlements/ingest', [
            'file' => UploadedFile::fake()->createWithContent('s.csv', $csv),
        ], ['Accept' => 'application/json'])->assertStatus(200)
            ->assertJsonPath('data.summary.matched', 1);
    }

    private function makeProduct(): Product
    {
        $name = 'SF25 Product '.Str::random(4);

        return Product::factory()->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'show_on_website' => true,
            'is_active' => true,
            'status' => 'active',
            'track_inventory' => false,
            'stock_quantity' => 100,
            'price' => 50,
            'tax_rate' => 10,
        ]);
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
