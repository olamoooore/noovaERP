<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\SuperAdmin\Application\CatalogService;
use App\SuperAdmin\Application\FeatureGateService;
use App\SuperAdmin\Application\ModuleCatalogSyncService;
use App\SuperAdmin\Domain\CatalogItem;
use App\SuperAdmin\Domain\CatalogItemType;
use App\SuperAdmin\Domain\TenantEntitlement;
use Database\Seeders\ModuleFeatureSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Modules\Storefront\Enums\OrderStatus;
use Modules\Storefront\Enums\PaymentStatus;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontOrderItem;
use Modules\Storefront\Services\OrderService;
use Tests\TenantTestCase;

/**
 * SF-012 — Staff order console API (FR-ADM-01/04) over real HTTP:
 * permission matrix legs (view vs write vs none), machine-gated status
 * writes (409 + allowed set), actor attribution, and route wiring.
 */
class SF012AdminOrderConsoleTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET search_path TO public');
        $this->bindTenantContext((string) Str::uuid());
        $this->seed(RolePermissionSeeder::class);

        CatalogItemType::firstOrCreate(['key' => 'module'], ['name' => 'Module', 'sort_order' => 0]);
        app(ModuleCatalogSyncService::class)->sync();
        app(CatalogService::class)->syncFromLegacyModuleCatalog();
        $this->seed(ModuleFeatureSeeder::class);

        $this->tenantId = (string) Str::uuid();
        DB::table('public.tenants')->updateOrInsert(['id' => $this->tenantId], [
            'name' => 'SF012 Tenant', 'email' => 'sf012-'.Str::random(6).'@example.test',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->bindTenantContext($this->tenantId);

        $item = CatalogItem::where('key', 'storefront')->firstOrFail();
        TenantEntitlement::query()->create([
            'tenant_id' => $this->tenantId, 'catalog_item_id' => $item->id,
            'is_entitled' => true, 'source' => TenantEntitlement::SOURCE_OVERRIDE_INCLUDE, 'resolved_at' => now(),
        ]);
        app(FeatureGateService::class)->invalidate($this->tenantId);
    }

    public function test_view_role_can_read_but_not_write_and_no_role_role_is_denied(): void
    {
        $viewer = $this->makeTenantUser('viewer', 'Viewer');
        $accountant = $this->makeTenantUser('accountant', 'Accountant');

        // viewer: has storefront.view (matrix), no write.
        $this->actingAs($viewer)->getJson('/api/v1/admin/storefront/orders')->assertStatus(200);
        $this->actingAs($viewer)->postJson('/api/v1/admin/storefront/orders/1/status', ['status' => 'processing'])
            ->assertStatus(403)->assertJsonPath('required_permission', 'storefront.write');

        // accountant: NO storefront.* grant at all — must be denied READ even
        // though accountants hold sales.view/invoices.view (proves the gate is
        // storefront.* and not inherited from sales.*).
        $this->actingAs($accountant)->getJson('/api/v1/admin/storefront/orders')
            ->assertStatus(403)->assertJsonPath('required_permission', 'storefront.view');
    }

    public function test_status_writes_go_through_the_machine_with_actor_audit(): void
    {
        $manager = $this->makeTenantUser('manager', 'Manager'); // holds storefront.write
        $order = $this->makeOrder();

        // pending -> processing is NOT an edge (must be paid first).
        $res = $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/status", [
            'status' => 'processing',
        ]);
        $res->assertStatus(409)->assertJsonPath('code', 'invalid_transition');
        $this->assertSame(OrderStatus::PENDING->value, $order->fresh()->status);

        // paid -> processing is allowed (seed via GATEWAY actor, as a webhook would).
        app(OrderService::class)->transitionOrder($order, OrderStatus::PAID, \Modules\Storefront\Enums\ActorType::GATEWAY);
        $this->actingAs($manager)->postJson("/api/v1/admin/storefront/orders/{$order->id}/status", [
            'status' => 'processing', 'note' => 'packed',
        ])->assertStatus(200)->assertJsonPath('data.status', 'processing');

        $last = $order->fresh()->statusHistory()->latest('id')->first();
        $this->assertSame('staff', $last->actor_type);
        $this->assertSame('packed', $last->note);
    }

    public function test_list_filters_search_and_detail_shape(): void
    {
        $admin = $this->makeTenantUser('admin-x', 'Admin');
        $orders = app(OrderService::class);

        $paid = $this->makeOrder(['email' => 'findme-'.Str::random(6).'@example.test']);
        // Seed like a real webhook completion: payment machine, then the
        // fulfillment machine (both via the shared OrderService).
        $svc = app(OrderService::class);
        $svc->transitionPayment($paid, PaymentStatus::PAID, \Modules\Storefront\Enums\ActorType::GATEWAY);
        $svc->transitionOrder($paid->fresh(), OrderStatus::PAID, \Modules\Storefront\Enums\ActorType::GATEWAY);
        $this->makeOrder(); // default pending

        $all = $this->actingAs($admin)->getJson('/api/v1/admin/storefront/orders');
        $all->assertStatus(200);
        $this->assertCount(2, $all->json('data'));
        $this->assertNotEmpty($all->json('meta.allowed_transitions.pending'), 'machine map published for UI enablement');

        $filtered = $this->actingAs($admin)->getJson('/api/v1/admin/storefront/orders?status=processing');
        $this->assertCount(0, $filtered->json('data'));

        $paidList = $this->actingAs($admin)->getJson('/api/v1/admin/storefront/orders?payment_status=paid');
        $this->assertCount(1, $paidList->json('data'));

        $search = $this->actingAs($admin)->getJson('/api/v1/admin/storefront/orders?search='.urlencode(mb_substr($paid->email, 0, 12)));
        $search->assertStatus(200)->assertJsonPath('data.0.id', $paid->id);

        // Detail: items + history + attempts + allowed set.
        StorefrontOrderItem::create([
            'order_id' => $paid->id, 'name' => 'Line One', 'unit_price' => 12.5,
            'quantity' => 2, 'line_total' => 25,
        ]);
        $orders->recordPaymentAttempt($paid, 'stripe', 'pi_sf012', 'declined', 'card declined');

        $detail = $this->actingAs($admin)->getJson("/api/v1/admin/storefront/orders/{$paid->id}");
        $detail->assertStatus(200)
            ->assertJsonPath('data.totals.total', '10.00')
            ->assertJsonPath('data.items.0.name', 'Line One')
            ->assertJsonPath('data.payment_attempts.0.error', 'card declined');
        $this->assertContains(OrderStatus::PROCESSING->value, $detail->json('data.allowed_transitions'));
        $this->assertCount(2, $detail->json('data.history'), 'both accepted transitions recorded (payment + fulfillment)');
    }

    public function test_routes_carry_permission_strings_and_entitlement(): void
    {
        $expected = [
            'api/v1/admin/storefront/orders' => 'storefront.view',
            'api/v1/admin/storefront/orders/{order}' => 'storefront.view',
            'api/v1/admin/storefront/orders/{order}/status' => 'storefront.write',
            'api/v1/admin/storefront/settings' => null,
        ];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! array_key_exists($uri, $expected)) {
                continue;
            }
            $mw = $route->middleware();
            $this->assertContains('entitlement:storefront', $mw, $uri);
            if ($expected[$uri] !== null) {
                $this->assertContains('App\\Http\\Middleware\\RequirePermission:'.$expected[$uri], $mw, $uri);
            }
        }
    }

    // ─── Helpers ────────────────────────────────────────────────────────

    private function makeOrder(array $overrides = []): StorefrontOrder
    {
        return StorefrontOrder::create(array_merge([
            'order_number' => app(OrderService::class)->generateOrderNumber(),
            'email' => 'buyer-'.Str::random(8).'@example.test',
            'status' => OrderStatus::PENDING->value,
            'payment_status' => PaymentStatus::PENDING_PAYMENT->value,
            'subtotal' => 10, 'total' => 10, 'currency' => 'USD',
            'placed_at' => now(),
        ], $overrides));
    }

    private function makeTenantUser(string $name, string $spatieRole): User
    {
        $user = User::factory()->create(['name' => $name]);
        DB::table('public.users')->updateOrInsert(
            ['email' => $user->email],
            ['name' => $user->name, 'password' => $user->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $user->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => mb_strtolower($spatieRole), 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $user->assignRole($spatieRole);
        $this->bindTenantContext($this->tenantId);

        return $user;
    }
}
