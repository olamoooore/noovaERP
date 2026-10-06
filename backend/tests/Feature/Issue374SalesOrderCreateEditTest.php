<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Feature;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SalesApprovalGate;
use App\SuperAdmin\Application\FeatureGateService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Tests\TenantTestCase;

/**
 * Issue #374 (SALES-SO / O5): a sales order must be creatable and editable
 * directly. `PUT /sales/orders/{id}` previously accepted header fields only —
 * the lines of a DRAFT order could not be edited at all (they were silently
 * dropped from the payload).
 *
 * Acceptance: draft line editing goes through the service with the same
 * engine-first pricing/tax resolution as order creation (PricingService for
 * the price, TaxResolutionService for inclusive/exclusive, TaxEngineService
 * arithmetic), and the totals are recalculated. Lines stay editable only in
 * draft; the #407 lock/amend rules keep governing confirmed orders.
 *
 * Every test goes through the real HTTP route (router -> middleware ->
 * controller).
 */
class Issue374SalesOrderCreateEditTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private User $viewer;

    private Customer $customer;

    private Product $productA;

    private Product $productB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureFeaturesTableExists();
        $this->mockFeatureGate(true);

        $this->tenantId = (string) Str::uuid();
        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Issue 374 SO Create Edit Tenant',
            'email' => 'issue374-'.uniqid().'@test.local',
            'plan' => 'standard',
        ]));
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Setting::setValue(SalesApprovalGate::SETTING_KEY, null);
        Setting::setValue('sales.orders.auto_lock_on_confirm', false);

        $this->admin = $this->makeUser('admin', UserRole::ADMIN);
        $this->viewer = $this->makeUser('viewer', UserRole::VIEWER);

        $this->customer = Customer::factory()->create([
            'payment_terms' => 30,
            'credit_limit' => 100000,
        ]);

        $this->productA = Product::factory()->create([
            'name' => 'Issue 374 Widget A',
            'sku' => 'I374-A-'.uniqid(),
            'price' => 10,
            'cost' => 4,
            'track_inventory' => false,
        ]);
        $this->productB = Product::factory()->create([
            'name' => 'Issue 374 Widget B',
            'sku' => 'I374-B-'.uniqid(),
            'price' => 20,
            'cost' => 8,
            'track_inventory' => false,
        ]);

        $this->actingAs($this->admin);
    }

    private function makeUser(string $accessRole, UserRole $spatieRole): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole->spatieName());

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $accessRole,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function createDraftOrder(): int
    {
        $response = $this->postJson('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'channel' => 'admin',
            'lines' => [[
                'line_type' => 'product',
                'product_id' => $this->productA->id,
                'description' => $this->productA->name,
                'quantity' => 10,
                'unit_price' => 10,
                'tax_rate' => 0,
                'discount_amount' => 0,
            ]],
        ]);
        $response->assertCreated();

        return (int) $response->json('id');
    }

    public function test_create_through_router_prices_lines_and_totals(): void
    {
        $response = $this->postJson('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'lines' => [
                [
                    'line_type' => 'product',
                    'product_id' => $this->productA->id,
                    'description' => $this->productA->name,
                    'quantity' => 2,
                    'unit_price' => 10,
                    'tax_rate' => 10,
                ],
                [
                    // No price sent: the pricing engine resolves the
                    // catalogue price (20), the browser value is not needed.
                    'line_type' => 'product',
                    'product_id' => $this->productB->id,
                    'description' => $this->productB->name,
                    'quantity' => 1,
                    'price_mode' => 'auto',
                    'tax_rate' => 0,
                ],
            ],
        ]);

        $response->assertCreated();
        $order = SalesOrder::with('lines')->findOrFail((int) $response->json('id'));

        $this->assertTrue($order->isDraft());
        $this->assertCount(2, $order->lines);
        $this->assertEqualsWithDelta(20.0, (float) $order->lines->firstWhere('product_id', $this->productB->id)->unit_price, 0.001);
        $this->assertEqualsWithDelta(40.0, (float) $order->subtotal, 0.001);
        $this->assertEqualsWithDelta(2.0, (float) $order->tax_amount, 0.001);
        $this->assertEqualsWithDelta(42.0, (float) $order->total, 0.001);
    }

    public function test_draft_update_with_lines_replaces_lines_and_recalculates_totals(): void
    {
        $orderId = $this->createDraftOrder();
        $before = SalesOrder::findOrFail($orderId);
        $this->assertEqualsWithDelta(100.0, (float) $before->total, 0.001);

        $response = $this->putJson("/api/v1/sales/orders/{$orderId}", [
            'notes' => 'Customer revised the quantities',
            'lines' => [
                [
                    'line_type' => 'product',
                    'product_id' => $this->productA->id,
                    'description' => $this->productA->name,
                    'quantity' => 3,
                    'unit_price' => 10,
                    'tax_rate' => 10,
                ],
                [
                    'line_type' => 'section',
                    'description' => 'Accessories',
                ],
                [
                    // Engine-resolved price: no unit_price from the browser.
                    'line_type' => 'product',
                    'product_id' => $this->productB->id,
                    'description' => $this->productB->name,
                    'quantity' => 1,
                    'price_mode' => 'auto',
                    'tax_rate' => 0,
                ],
            ],
        ]);

        $response->assertOk();

        $order = SalesOrder::with(['lines' => fn ($q) => $q->orderBy('sequence')])->findOrFail($orderId);
        $this->assertSame('Customer revised the quantities', (string) $order->notes);
        $this->assertCount(3, $order->lines, 'The draft lines must be replaced by the submitted set.');

        $lineA = $order->lines->firstWhere('product_id', $this->productA->id);
        $lineB = $order->lines->firstWhere('product_id', $this->productB->id);
        $this->assertEqualsWithDelta(3.0, (float) $lineA->quantity, 0.0001);
        $this->assertEqualsWithDelta(33.0, (float) $lineA->line_total, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $lineB->unit_price, 0.001, 'Auto lines are priced by the engine.');
        $this->assertSame('section', $order->lines[1]->line_type);

        $this->assertEqualsWithDelta(50.0, (float) $order->subtotal, 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $order->tax_amount, 0.001);
        $this->assertEqualsWithDelta(53.0, (float) $order->total, 0.001);
        $this->assertEqualsWithDelta(53.0, (float) $response->json('total'), 0.001);

        // The draft line edit leaves an append-only audit trail (#895).
        $this->assertTrue(
            AuditLog::query()
                ->where('reference_type', 'sales_order')
                ->where('reference_id', $orderId)
                ->where('operation', 'sales_order_updated')
                ->exists(),
            'A draft line edit must be audited.',
        );
    }

    public function test_draft_update_rejects_a_line_without_description(): void
    {
        $orderId = $this->createDraftOrder();

        $this->putJson("/api/v1/sales/orders/{$orderId}", [
            'lines' => [['product_id' => $this->productA->id, 'quantity' => 4]],
        ])->assertStatus(422);

        $order = SalesOrder::with('lines')->findOrFail($orderId);
        $this->assertCount(1, $order->lines);
        $this->assertEqualsWithDelta(10.0, (float) $order->lines->first()->quantity, 0.0001);
        $this->assertEqualsWithDelta(100.0, (float) $order->total, 0.001);
    }

    public function test_cancelled_order_rejects_line_edits(): void
    {
        $orderId = $this->createDraftOrder();
        $this->postJson("/api/v1/sales/orders/{$orderId}/cancel", ['reason' => 'Customer withdrew'])
            ->assertOk();

        $this->putJson("/api/v1/sales/orders/{$orderId}", [
            'lines' => [[
                'product_id' => $this->productB->id,
                'description' => $this->productB->name,
                'quantity' => 1,
                'unit_price' => 20,
            ]],
        ])->assertStatus(403);

        $order = SalesOrder::with('lines')->findOrFail($orderId);
        $this->assertCount(1, $order->lines);
        $this->assertSame($this->productA->id, (int) $order->lines->first()->product_id);
        $this->assertEqualsWithDelta(100.0, (float) $order->total, 0.001);
    }

    public function test_viewer_cannot_create_or_edit(): void
    {
        $orderId = $this->createDraftOrder();
        $this->actingAs($this->viewer);

        $this->postJson('/api/v1/sales/orders', [
            'customer_id' => $this->customer->id,
            'lines' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1]],
        ])->assertStatus(403);

        $this->putJson("/api/v1/sales/orders/{$orderId}", [
            'lines' => [[
                'product_id' => $this->productB->id,
                'description' => $this->productB->name,
                'quantity' => 1,
                'unit_price' => 20,
            ]],
        ])->assertStatus(403);

        $this->assertEqualsWithDelta(100.0, (float) SalesOrder::findOrFail($orderId)->total, 0.001);
    }

    private function mockFeatureGate(bool $isEntitled): void
    {
        $mock = \Mockery::mock(FeatureGateService::class);
        $mock->shouldReceive('check')
            ->andReturnUsing(fn (string $tenantId, string $key) => [
                'key' => $key,
                'is_entitled' => $isEntitled,
                'source' => $isEntitled ? 'tier_included' : null,
                'name' => strtoupper($key),
            ]);
        $mock->shouldReceive('all')
            ->andReturn($isEntitled ? [['key' => 'sales', 'source' => 'tier_included', 'name' => 'SALES']] : []);
        $mock->shouldReceive('invalidate')->andReturnNull();

        app()->instance(FeatureGateService::class, $mock);
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

        Feature::query()->updateOrCreate(
            ['key' => 'sales'],
            [
                'name' => 'Sales',
                'description' => 'Sales module feature gate for tests',
                'is_core' => false,
                'price' => 0,
            ]
        );
    }
}
