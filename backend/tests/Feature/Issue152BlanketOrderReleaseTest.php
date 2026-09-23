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
