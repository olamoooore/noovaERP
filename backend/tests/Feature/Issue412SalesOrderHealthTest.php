<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ApprovalRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SalesApprovalGate;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\PurchaseOrderItem;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\StockReservation;
use Modules\Inventory\Models\Supplier;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Services\SalesOrderService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #412 (SALES-DIFF DX2): order health panel before Confirm/Send.
 *
 * GET /api/v1/sales/orders/{id}/health is a read-only dry run that reports,
 * per check, pass / warn / fail:
 *  - stock per line (available, or backorder with the date from incoming
 *    purchase orders; promise date #408 at risk),
 *  - customer credit exposure vs limit — the SAME verdict confirm gates on
 *    (CreditCheckService, base currency, committed open orders #240/#383),
 *  - whether approval will be required and why (SalesApprovalGate),
 *  - margin per line and total, only for roles holding pricelists.cost.
 *
 * Driven through the real route (router → middleware → controller).
 */
class Issue412SalesOrderHealthTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $tracked;

    private Product $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->mockFeatureGate();

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Issue412 Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->admin = $this->makeUser('admin');
        $this->actingAs($this->admin);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'credit_limit' => 10000, 'total_outstanding' => 0]);
        $this->warehouse = Warehouse::create(['name' => 'I412 WH', 'code' => 'I412-'.uniqid(), 'is_active' => true, 'is_default' => true]);

        $this->tracked = Product::factory()->create([
            'name' => 'I412 Widget',
            'sku' => 'I412-W-'.uniqid(),
            'track_inventory' => true,
            'price' => 100,
            'cost' => 40,
            'stock_quantity' => 0,
        ]);
        $this->service = Product::factory()->create([
            'name' => 'I412 Service',
            'sku' => 'I412-S-'.uniqid(),
            'track_inventory' => false,
            'price' => 100,
            'cost' => 40,
        ]);

        StockLevel::create([
            'product_id' => $this->tracked->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 3,
            'reserved_quantity' => 0,
            'min_quantity' => 0,
        ]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole(UserRole::from($role)->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $role,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    /**
     * @param  array<int, array{0: Product, 1: float, 2: float}>  $lines  [product, quantity, unit price]
     */
    private function order(array $lines, array $extra = []): SalesOrder
    {
        return app(SalesOrderService::class)->createOrder(array_merge([
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->admin->id,
            'lines' => array_map(fn (array $line) => [
                'product_id' => $line[0]->id,
                'description' => $line[0]->name,
                'quantity' => $line[1],
                'unit_price' => $line[2],
                'tax_rate' => 0,
            ], $lines),
        ], $extra));
    }

    private function incomingPurchaseOrder(float $quantity, string $expectedDate): PurchaseOrder
    {
        $supplier = Supplier::firstOrCreate(['code' => 'SUP-412'], ['name' => 'I412 Supplier', 'is_active' => true]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO-412-'.uniqid(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'expected_date' => $expectedDate,
            'created_by' => $this->admin->id,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $this->tracked->id,
            'description' => $this->tracked->name,
            'quantity_ordered' => $quantity,
            'quantity_received' => 0,
            'unit_cost' => 40,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount_percent' => 0,
            'line_total' => $quantity * 40,
        ]);

        return $po;
    }

    private function check(array $json, string $key): array
    {
        foreach ($json['checks'] as $check) {
            if ($check['key'] === $key) {
                return $check;
            }
        }

        $this->fail("No '{$key}' check in the health response.");
    }

    private function lineFor(array $json, int $productId): array
    {
        foreach ($json['lines'] as $line) {
            if ((int) $line['product_id'] === $productId) {
                return $line;
            }
        }

        $this->fail("No health line for product {$productId}.");
    }

    // ── Stock per line ────────────────────────────────────────────────────

    public function test_stock_per_line_reports_available_or_backorder_with_the_incoming_purchase_order_date(): void
    {
        $arrival = now()->addDays(14)->toDateString();
        $this->incomingPurchaseOrder(10, $arrival);

        // Promised before the stock arrives (#408): the promise is at risk.
        $order = $this->order(
            [[$this->tracked, 5, 100], [$this->service, 1, 100]],
            ['commitment_date' => now()->addDays(7)->toDateString()],
        );

        $response = $this->getJson("/api/v1/sales/orders/{$order->id}/health")->assertOk();
        $json = $response->json();

        $widget = $this->lineFor($json, $this->tracked->id);
        $this->assertSame('warn', $widget['status']);
        $this->assertEqualsWithDelta(3.0, (float) $widget['available'], 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $widget['shortage'], 0.0001);
        $this->assertSame($arrival, $widget['backorder_date'], 'Backorder date comes from the incoming purchase order.');
        $this->assertTrue($widget['promise_at_risk']);

        $service = $this->lineFor($json, $this->service->id);
        $this->assertSame('pass', $service['status']);
        $this->assertFalse($service['tracked']);

        // Confirm backorders a short line rather than refusing — advisory.
        $this->assertSame('warn', $this->check($json, 'stock')['status']);
    }

    public function test_stock_is_a_pass_when_every_tracked_line_is_available(): void
    {
        $order = $this->order([[$this->tracked, 2, 100]]);

        $json = $this->getJson("/api/v1/sales/orders/{$order->id}/health")->assertOk()->json();

        $this->assertSame('pass', $this->lineFor($json, $this->tracked->id)['status']);
        $this->assertNull($this->lineFor($json, $this->tracked->id)['backorder_date']);
        $this->assertSame('pass', $this->check($json, 'stock')['status']);
    }

    // ── Credit exposure (same verdict confirm gates on) ───────────────────

    public function test_credit_check_uses_the_confirm_basis_and_fails_when_confirm_would_be_refused(): void
    {
        // 6,000 confirmed and uninvoiced: committed exposure (#240/#384).
        $other = $this->order([[$this->service, 60, 100]]);
        $this->postJson("/api/v1/sales/orders/{$other->id}/confirm")->assertOk();

        $order = $this->order([[$this->service, 50, 100]]); // 5,000 > 4,000 available

        $json = $this->getJson("/api/v1/sales/orders/{$order->id}/health")->assertOk()->json();

        $credit = $this->check($json, 'credit');
        $this->assertSame('fail', $credit['status'], 'Confirm hard-refuses over-limit orders, so the panel fails the check.');
        $this->assertFalse($json['credit']['approved']);
        $this->assertEqualsWithDelta(10000.0, (float) $json['credit']['credit_limit'], 0.001);
        $this->assertEqualsWithDelta(4000.0, (float) $json['credit']['available_credit'], 0.001);
        $this->assertEqualsWithDelta(6000.0, (float) $json['credit']['exposure'], 0.001);
        $this->assertEqualsWithDelta(5000.0, (float) $json['credit']['document_total'], 0.001);
        $this->assertFalse($json['ready']);

        // The panel agrees with the gate.
        $this->postJson("/api/v1/sales/orders/{$order->id}/confirm")->assertStatus(422);
    }

    public function test_credit_check_passes_within_the_limit(): void
    {
        $order = $this->order([[$this->service, 20, 100]]);

        $json = $this->getJson("/api/v1/sales/orders/{$order->id}/health")->assertOk()->json();

        $this->assertSame('pass', $this->check($json, 'credit')['status']);
        $this->assertTrue($json['credit']['approved']);
        $this->assertTrue($json['ready']);
    }

    // ── Approval (SalesApprovalGate) ──────────────────────────────────────

    public function test_approval_reports_that_confirm_will_request_approval_and_why_without_raising_a_request(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, '1000');

        $order = $this->order([[$this->service, 20, 100]]); // 2,000 ≥ 1,000

        $json = $this->getJson("/api/v1/sales/orders/{$order->id}/health")->assertOk()->json();

        $approval = $this->check($json, 'approval');
        $this->assertSame('warn', $approval['status']);
        $this->assertTrue($json['approval']['required']);
        $this->assertSame('not_requested', $json['approval']['state']);
        $this->assertEqualsWithDelta(1000.0, (float) $json['approval']['threshold'], 0.001);
        $this->assertStringContainsString('1,000.00', $approval['detail']);

        $this->assertSame(0, ApprovalRequest::query()->count(), 'A dry run must not raise an approval request.');
    }

    public function test_approval_passes_when_no_approval_is_needed(): void
    {
        $order = $this->order([[$this->service, 20, 100]]);

        $json = $this->getJson("/api/v1/sales/orders/{$order->id}/health")->assertOk()->json();

        $this->assertSame('pass', $this->check($json, 'approval')['status']);
        $this->assertFalse($json['approval']['required']);
    }

    // ── Margin (cost-permission gated) ────────────────────────────────────

    public function test_margin_per_line_and_total_for_a_role_holding_the_cost_permission(): void
    {
        $order = $this->order([[$this->service, 10, 100]]); // revenue 1,000, cost 400

        $json = $this->getJson("/api/v1/sales/orders/{$order->id}/health")->assertOk()->json();

        $this->assertTrue($json['can_see_cost']);
        $this->assertEqualsWithDelta(1000.0, (float) $json['margin']['total']['revenue'], 0.001);
        $this->assertEqualsWithDelta(400.0, (float) $json['margin']['total']['cost'], 0.001);
        $this->assertEqualsWithDelta(600.0, (float) $json['margin']['total']['margin'], 0.001);
        $this->assertEqualsWithDelta(60.0, (float) $json['margin']['total']['margin_percent'], 0.001);

        $line = $this->lineFor($json, $this->service->id);
        $this->assertEqualsWithDelta(60.0, (float) $line['margin_percent'], 0.001);
    }

    public function test_margin_and_cost_are_hidden_from_a_role_without_the_cost_permission(): void
    {
        $order = $this->order([[$this->service, 10, 100]]);

        $this->actingAs($this->makeUser('sales'));
        $response = $this->getJson("/api/v1/sales/orders/{$order->id}/health")->assertOk();
        $json = $response->json();

        $this->assertFalse($json['can_see_cost']);
        $this->assertNull($json['margin']);
        $this->assertArrayNotHasKey('margin_percent', $this->lineFor($json, $this->service->id));
        $this->assertNull(collect($json['checks'])->firstWhere('key', 'margin'));
        $this->assertStringNotContainsString('"cost"', $response->getContent());
    }

    // ── Read-only + access ────────────────────────────────────────────────

    public function test_health_is_read_only(): void
    {
        Setting::setValue(SalesApprovalGate::SETTING_KEY, '1000');
        $this->incomingPurchaseOrder(10, now()->addDays(14)->toDateString());
        $order = $this->order([[$this->tracked, 5, 100]]);
        $before = $order->fresh()->toArray();

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->getJson("/api/v1/sales/orders/{$order->id}/health")->assertOk();

        $this->assertSame([], $writes, 'The health dry run must not write anything.');
        $this->assertSame(0, ApprovalRequest::query()->count());
        $this->assertSame(0, StockReservation::query()->count());
        $this->assertSame('draft', $order->fresh()->status);
        $this->assertEquals($before, $order->fresh()->toArray());
    }

    public function test_health_uses_the_order_view_permission(): void
    {
        $order = $this->order([[$this->service, 1, 100]]);

        $this->actingAs($this->makeUser('viewer'));
        $this->getJson("/api/v1/sales/orders/{$order->id}/health")->assertOk();

        $this->actingAs($this->makeUser('accountant'));
        $this->getJson("/api/v1/sales/orders/{$order->id}/health")->assertForbidden();
    }

    public function test_health_respects_the_legal_entity_scope_of_order_show(): void
    {
        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'I412 Group', 'code' => 'G412-'.Str::upper(Str::random(5)), 'base_currency' => 'USD',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity A', 'code' => 'E412A-'.Str::upper(Str::random(4)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Entity B', 'code' => 'E412B-'.Str::upper(Str::random(4)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = $this->order([[$this->service, 1, 100]], ['legal_entity_id' => $entityA]);

        $outsider = $this->makeUser('manager');
        DB::table('user_entity_access')->insert([
            'user_id' => $outsider->id, 'legal_entity_id' => $entityB, 'role' => 'editor', 'is_default' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($outsider);
        $this->getJson("/api/v1/sales/orders/{$order->id}")->assertNotFound();
        $this->getJson("/api/v1/sales/orders/{$order->id}/health")->assertNotFound();
    }

    private function mockFeatureGate(): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')->andReturnUsing(fn (string $tenantId, string $key) => [
            'key' => $key, 'is_entitled' => true, 'source' => 'tier_included', 'name' => strtoupper($key),
        ]);
        $this->app->instance(FeatureGateService::class, $mock);
    }

    private function ensureFeaturesTableExists(): void
    {
        if (! Schema::hasTable('features')) {
            Schema::create('features', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_core')->default(false);
                $table->decimal('price', 10, 2)->default(0);
                $table->timestamps();
            });
        }
    }
}
