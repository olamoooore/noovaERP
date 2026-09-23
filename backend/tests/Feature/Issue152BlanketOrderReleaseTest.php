<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #152 — blanket orders / call-off agreements: committed quantity +
 * locked price, release orders drawn down against the agreement, price
 * consistency enforcement and committed/released/remaining visibility.
 */
class Issue152BlanketOrderReleaseTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '15215215-2152-5354-5556-575859505152',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'sales_blanket_orders', 'sales_blanket_order_lines', 'sales_orders',
            'sales_order_lines', 'customers', 'products',
        ]);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create(['price' => 12.00, 'cost' => 5.00]);
    }

    private function createBlanket(float $committed = 100, float $price = 10.00, ?string $validTo = null): int
    {
        $response = $this->actingAs($this->user)->postJson('/api/v1/sales/blanket-orders', [
            'customer_id' => $this->customer->id,
            'name' => 'Annual supply agreement',
            'valid_from' => now()->subMonth()->toDateString(),
            'valid_to' => $validTo ?? now()->addYear()->toDateString(),
            'currency' => 'USD',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Bulk widget',
                'committed_qty' => $committed,
                'unit_price' => $price,
            ]],
        ]);

        $response->assertStatus(201);

        return (int) $response->json('data.id');
    }

    private function blanketLineId(int $blanketId): int
    {
        return (int) DB::table('sales_blanket_order_lines')->where('blanket_order_id', $blanketId)->value('id');
    }

    public function test_release_creates_a_sales_order_and_decrements_the_commitment(): void
    {
        $blanketId = $this->createBlanket(committed: 100, price: 10.00);

        $response = $this->actingAs($this->user)->postJson("/api/v1/sales/blanket-orders/{$blanketId}/release", [
            'lines' => [
                ['blanket_line_id' => $this->blanketLineId($blanketId), 'quantity' => 30],
            ],
        ]);

        $response->assertStatus(201);

        // The release is a normal sales order priced at the agreed rate.
        $orderId = (int) $response->json('sales_order.id');
        $line = DB::table('sales_order_lines')->where('sales_order_id', $orderId)->first();
        $this->assertNotNull($line);
        $this->assertSame(30.0, round((float) $line->quantity, 4));
        $this->assertSame(10.0, round((float) $line->unit_price, 2), 'The agreed price must be used.');

        // Commitment visibility.
        $summary = $this->actingAs($this->user)->getJson("/api/v1/sales/blanket-orders/{$blanketId}");
        $summary->assertOk();
        $summaryLine = $summary->json('data.lines.0');
        $this->assertSame(100.0, (float) $summaryLine['committed_qty']);
        $this->assertSame(30.0, (float) $summaryLine['released_qty']);
        $this->assertSame(70.0, (float) $summaryLine['remaining_qty']);
        $this->assertSame(700.0, (float) $summary->json('data.totals.remaining_value'));
    }

    /**
     * Issue #152 (hostile re-review): cancelling a release order must give
     * the committed quantity back.
     */
    public function test_cancelling_a_release_order_restores_the_commitment(): void
    {
        $blanketId = $this->createBlanket(committed: 100, price: 10.00);

        $release = $this->actingAs($this->user)->postJson("/api/v1/sales/blanket-orders/{$blanketId}/release", [
            'lines' => [
                ['blanket_line_id' => $this->blanketLineId($blanketId), 'quantity' => 30],
            ],
        ])->assertStatus(201);

        $orderId = (int) $release->json('sales_order.id');

        // 30 consumed, 70 remaining.
        $this->assertSame(70.0, $this->remainingQty($blanketId));

        $this->actingAs($this->user)->postJson("/api/v1/sales/orders/{$orderId}/cancel", [
            'reason' => 'Customer cancelled the call-off',
        ])->assertOk();

        $this->assertSame(100.0, $this->remainingQty($blanketId),
            'Cancelling a release order must restore the committed quantity.');
        $this->assertSame(0.0, (float) DB::table('sales_blanket_order_lines')
            ->where('blanket_order_id', $blanketId)->value('released_qty'));

        // The restored quantity can be released again.
        $this->actingAs($this->user)->postJson("/api/v1/sales/blanket-orders/{$blanketId}/release", [
            'lines' => [
                ['blanket_line_id' => $this->blanketLineId($blanketId), 'quantity' => 80],
            ],
        ])->assertStatus(201);
        $this->assertSame(20.0, $this->remainingQty($blanketId));
    }

    /**
     * Issue #152 (hostile re-review): agreements were not entity-scoped.
     */
    public function test_blanket_orders_are_entity_scoped(): void
    {
        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Blanket Entity Group',
            'code' => 'BEG-'.strtoupper(substr(md5(uniqid()), 0, 5)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Blanket Entity A', 'code' => 'BEA-'.strtoupper(substr(md5(uniqid()), 0, 5)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Blanket Entity B', 'code' => 'BEB-'.strtoupper(substr(md5(uniqid()), 0, 5)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->customer->update(['legal_entity_id' => $entityA]);
        $customerB = Customer::factory()->create(['legal_entity_id' => $entityB]);

        $blanketA = $this->createBlanket(committed: 100, price: 10.00);

        $blanketB = (int) $this->actingAs($this->user)->postJson('/api/v1/sales/blanket-orders', [
            'customer_id' => $customerB->id,
            'name' => 'Other entity agreement',
            'valid_from' => now()->subMonth()->toDateString(),
            'valid_to' => now()->addYear()->toDateString(),
            'currency' => 'USD',
            'lines' => [[
                'product_id' => $this->product->id,
                'description' => 'Bulk widget',
                'committed_qty' => 50,
                'unit_price' => 10.00,
            ]],
        ])->assertStatus(201)->json('data.id');

        // Grant the user access to entity A only.
        DB::table('user_entity_access')->insert([
            'user_id' => $this->user->id,
            'legal_entity_id' => $entityA,
            'role' => 'viewer',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $index = $this->actingAs($this->user)->getJson('/api/v1/sales/blanket-orders')->assertOk();
        $ids = collect($index->json('data'))->pluck('id')->all();
        $this->assertContains($blanketA, $ids);
        $this->assertNotContains($blanketB, $ids, 'Another entity\'s agreement must not appear in the list.');

        $this->actingAs($this->user)
            ->getJson("/api/v1/sales/blanket-orders/{$blanketB}")
            ->assertStatus(404);

        $this->actingAs($this->user)
            ->postJson("/api/v1/sales/blanket-orders/{$blanketB}/release", [
                'lines' => [['blanket_line_id' => $this->blanketLineId($blanketB), 'quantity' => 5]],
            ])
            ->assertStatus(404);
    }

    private function remainingQty(int $blanketId): float
    {
        $line = DB::table('sales_blanket_order_lines')->where('blanket_order_id', $blanketId)->first();

        return round((float) $line->committed_qty - (float) $line->released_qty, 4);
    }

    public function test_release_beyond_the_remaining_commitment_is_rejected(): void
    {
        $blanketId = $this->createBlanket(committed: 100);

        $this->actingAs($this->user)->postJson("/api/v1/sales/blanket-orders/{$blanketId}/release", [
            'lines' => [['blanket_line_id' => $this->blanketLineId($blanketId), 'quantity' => 80]],
        ])->assertStatus(201);

        $this->actingAs($this->user)->postJson("/api/v1/sales/blanket-orders/{$blanketId}/release", [
            'lines' => [['blanket_line_id' => $this->blanketLineId($blanketId), 'quantity' => 30]],
        ])->assertStatus(422);

        $this->assertSame(1, DB::table('sales_orders')->count(), 'The rejected release must not create an order.');
        $this->assertSame(80.0, round((float) DB::table('sales_blanket_order_lines')->where('id', $this->blanketLineId($blanketId))->value('released_qty'), 4));
    }

    public function test_release_price_must_match_the_agreement_unless_overridden(): void
    {
        $blanketId = $this->createBlanket(committed: 100, price: 10.00);
        $lineId = $this->blanketLineId($blanketId);

        // Divergent price without the override -> rejected.
        $this->actingAs($this->user)->postJson("/api/v1/sales/blanket-orders/{$blanketId}/release", [
            'lines' => [['blanket_line_id' => $lineId, 'quantity' => 5, 'unit_price' => 8.00]],
        ])->assertStatus(422);

        // Explicit override -> allowed, and the release carries the new price.
        $response = $this->actingAs($this->user)->postJson("/api/v1/sales/blanket-orders/{$blanketId}/release", [
            'lines' => [['blanket_line_id' => $lineId, 'quantity' => 5, 'unit_price' => 8.00]],
            'allow_price_override' => true,
        ]);
        $response->assertStatus(201);

        $orderId = (int) $response->json('sales_order.id');
        $this->assertSame(8.0, round((float) DB::table('sales_order_lines')->where('sales_order_id', $orderId)->value('unit_price'), 2));
    }

    public function test_expired_or_cancelled_agreements_cannot_be_released(): void
    {
        $blanketId = $this->createBlanket(committed: 100, validTo: now()->subDay()->toDateString());

        $this->actingAs($this->user)->postJson("/api/v1/sales/blanket-orders/{$blanketId}/release", [
            'lines' => [['blanket_line_id' => $this->blanketLineId($blanketId), 'quantity' => 1]],
        ])->assertStatus(422);

        DB::table('sales_blanket_orders')->where('id', $blanketId)->update(['status' => 'cancelled']);

        $this->actingAs($this->user)->postJson("/api/v1/sales/blanket-orders/{$blanketId}/release", [
            'lines' => [['blanket_line_id' => $this->blanketLineId($blanketId), 'quantity' => 1]],
        ])->assertStatus(422);
    }
}
