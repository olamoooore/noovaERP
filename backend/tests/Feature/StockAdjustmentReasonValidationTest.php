<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccountMapping;
use App\Models\JournalEntryLink;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Inventory\Models\StockAdjustmentReason;
use Modules\Inventory\Models\StockLevel;
use Tests\TenantTestCase;

/**
 * Receive/Remove Stock must accept the tenant's real adjustment reasons.
 *
 * Regression: the backend compared the reason's direction (increase /
 * decrease / both) against the operation name (in / out), so every proper
 * increase reason was rejected for Receive Stock with "Selected reason is not
 * valid for this stock operation" — only 'both' reasons ever worked.
 */
class StockAdjustmentReasonValidationTest extends TenantTestCase
{
    protected User $user;

    protected Product $product;

    protected Warehouse $warehouse;

    /** @var array<string, StockAdjustmentReason> */
    protected array $reasons = [];

    protected string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(\App\Http\Middleware\EnsureInventoryAccess::class);

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Stock Reason Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $this->user = User::factory()->create();
        $this->user->assignRole(UserRole::MANAGER->spatieName());
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $this->tenantId,
            'role' => 'manager',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->warehouse = Warehouse::create([
            'name' => 'Reason Warehouse',
            'code' => 'RSN-'.uniqid(),
            'is_active' => true,
        ]);

        // Zero cost keeps GL posting out of this test (amount 0 skips it).
        $this->product = Product::factory()->create([
            'name' => 'Reason Widget',
            'sku' => 'RSN-'.uniqid(),
            'track_inventory' => true,
            'tracking_method' => 'none',
            'price' => 10,
            'cost' => 0,
        ]);

        foreach ([['increase', 'Found Stock'], ['decrease', 'Damaged Goods'], ['both', 'Opening Balance']] as $index => [$type, $name]) {
            $this->reasons[$type] = StockAdjustmentReason::create([
                'name' => $name,
                'code' => Str::slug($name).'-'.uniqid(),
                'type' => $type,
                'is_active' => true,
            ]);
        }
    }

    private function payload(int $reasonId, float $quantity = 1): array
    {
        return [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => $quantity,
            'adjustment_reason_id' => $reasonId,
        ];
    }

    public function test_receive_stock_accepts_an_increase_reason(): void
    {
        $response = $this->actingAs($this->user)->postJson(
            '/api/v1/stock/in',
            $this->payload($this->reasons['increase']->id),
        );

        $response->assertOk();

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'adjustment_reason_id' => $this->reasons['increase']->id,
        ]);
    }

    public function test_receive_stock_rejects_a_decrease_reason(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/stock/in', $this->payload($this->reasons['decrease']->id))
            ->assertStatus(422)
            ->assertJsonPath('errors.adjustment_reason_id.0', 'Selected reason is not valid for this stock operation.');
    }

    public function test_remove_stock_accepts_a_decrease_reason_and_rejects_an_increase_reason(): void
    {
        // Seed stock first so the removal has something to take.
        $this->actingAs($this->user)
            ->postJson('/api/v1/stock/in', $this->payload($this->reasons['increase']->id, 5))
            ->assertOk();

        $this->actingAs($this->user)
            ->postJson('/api/v1/stock/out', $this->payload($this->reasons['increase']->id))
            ->assertStatus(422);

        $this->actingAs($this->user)
            ->postJson('/api/v1/stock/out', $this->payload($this->reasons['decrease']->id))
            ->assertOk();
    }

    public function test_both_reasons_work_for_either_direction(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/stock/in', $this->payload($this->reasons['both']->id, 2))
            ->assertOk();

        $this->actingAs($this->user)
            ->postJson('/api/v1/stock/out', $this->payload($this->reasons['both']->id))
            ->assertOk();
    }

    /**
     * #1017 (decision 2026-10-07, option A): a stock increase posts nothing to
     * the GL when no gain account is configured (the 7300 mapping was removed),
     * and the receipt must still succeed with the stock/batch updated.
     */
    public function test_receive_stock_with_a_costed_product_and_no_gain_mapping_saves_without_gl(): void
    {
        // Production state after 7300 was removed.
        AccountMapping::where('mapping_key', 'inventory_adjustment_gain')->delete();

        $costed = Product::factory()->create([
            'name' => 'Costed Widget',
            'sku' => 'CST-'.uniqid(),
            'track_inventory' => true,
            'tracking_method' => 'none',
            'price' => 30,
            'cost' => 25,
        ]);

        $response = $this->actingAs($this->user)->postJson('/api/v1/stock/in', [
            'product_id' => $costed->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 4,
            'adjustment_reason_id' => $this->reasons['increase']->id,
        ]);

        $response->assertOk();

        // The stock increased by exactly the received quantity...
        $this->assertSame(
            4.0,
            round((float) $response->json('balance_after') - (float) $response->json('old_quantity'), 2),
            'The stock increase must be saved.',
        );

        // ...the level matches the movement...
        $this->assertSame(
            round((float) $response->json('balance_after'), 2),
            round((float) StockLevel::where('product_id', $costed->id)
                ->where('warehouse_id', $this->warehouse->id)
                ->value('quantity'), 2),
        );

        // ...and no GL posting is attempted (decision #1017).
        $this->assertSame(0, JournalEntryLink::where('source_document_type', 'stock_adjustment')->count(),
            'Decision #1017: an increase with no configured gain account posts nothing.');
    }
}
