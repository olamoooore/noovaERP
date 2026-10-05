<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BonusScheme;
use App\Models\BonusSchemeProduct;
use App\Models\BonusSchemeUsage;
use App\Models\Customer;
use App\Models\CustomerBonusScheme;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #570 (MC7): deliver() required status `allocated` and then stamped the
 * case `delivered` — a second delivery for the remaining lines was impossible,
 * and lines omitted from the request were treated as delivered (0 quantity).
 *
 * Usage is linked incrementally from completed DO lines (#562 / MC1) and the
 * case status is derived: `partially_delivered` until every line's allocated
 * quantity is linked, then `delivered`.
 */
class Issue570MedicalCasePartialDeliveryTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $productA;

    private Product $productB;

    private SalesOrderLine $salesOrderLineA;

    private SalesOrderLine $salesOrderLineB;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '57057057-0000-1111-2222-333333333333',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'medical_cases', 'medical_case_items', 'bonus_schemes',
            'bonus_scheme_products', 'customer_bonus_schemes', 'bonus_scheme_usages',
            'sales_order_lines', 'delivery_orders', 'delivery_order_lines',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::create([
            'name' => 'Issue570 User',
            'email' => 'issue570-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);
        $this->user->assignRole('admin');

        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->customer = Customer::factory()->create();
        $this->productA = Product::factory()->create();
        $this->productB = Product::factory()->create();

        $order = SalesOrder::create([
            'sales_order_number' => 'SO-570-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
        ]);
        $this->salesOrderLineA = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Issue 570 line A',
            'product_id' => $this->productA->id,
        ]);
        $this->salesOrderLineB = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Issue 570 line B',
            'product_id' => $this->productB->id,
        ]);

        $this->activeAssignmentFor($this->productA);
    }

    private function activeAssignmentFor(Product $product): CustomerBonusScheme
    {
        $scheme = BonusScheme::create([
            'scheme_code' => 'I570-'.Str::upper(Str::random(5)),
            'name' => 'Issue 570 Scheme',
            'start_date' => '2026-01-01',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        BonusSchemeProduct::create([
            'bonus_scheme_id' => $scheme->id,
            'qualifying_product_id' => $product->id,
            'scheme_type' => 'percentage_discount',
            'discount_percentage' => 10,
            'foc_qty' => 1,
            'status' => 'active',
        ]);

        return CustomerBonusScheme::create([
            'customer_id' => $this->customer->id,
            'bonus_scheme_id' => $scheme->id,
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
    }

    private function completedDeliveryOrderLine(Product $product, SalesOrderLine $salesOrderLine, float $deliveredQty): DeliveryOrderLine
    {
        $order = DeliveryOrder::create([
            'customer_id' => $this->customer->id,
            'status' => 'done',
            'created_by' => $this->user->id,
        ]);

        return DeliveryOrderLine::create([
            'delivery_order_id' => $order->id,
            'sales_order_line_id' => $salesOrderLine->id,
            'line_type' => 'product',
            'product_id' => $product->id,
            'quantity_ordered' => $deliveredQty,
            'quantity_delivered' => $deliveredQty,
            'unit_price' => 100,
        ]);
    }

    /**
     * @return array{0: MedicalCase, 1: MedicalCaseItem, 2: MedicalCaseItem}
     */
    private function allocatedCase(float $allocatedA = 10, float $allocatedB = 10): array
    {
        $case = MedicalCase::create([
            'customer_id' => $this->customer->id,
            'status' => 'allocated',
            'created_by' => $this->user->id,
        ]);

        $itemA = MedicalCaseItem::create([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $this->salesOrderLineA->id,
            'product_id' => $this->productA->id,
            'allocated_qty' => $allocatedA,
            'delivered_qty' => 0,
            'unit_price' => 100,
        ]);

        $itemB = MedicalCaseItem::create([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $this->salesOrderLineB->id,
            'product_id' => $this->productB->id,
            'allocated_qty' => $allocatedB,
            'delivered_qty' => 0,
            'unit_price' => 100,
        ]);

        return [$case, $itemA, $itemB];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function deliver(MedicalCase $case, array $items): TestResponse
    {
        return $this->actingAs($this->user)->postJson(
            '/api/v1/medical-cases/'.$case->id.'/deliver',
            ['items' => $items],
        );
    }

    public function test_a_partial_delivery_marks_the_case_partially_delivered_and_leaves_omitted_lines_untouched(): void
    {
        [$case, $itemA, $itemB] = $this->allocatedCase();
        $lineA = $this->completedDeliveryOrderLine($this->productA, $this->salesOrderLineA, 10);

        $this->deliver($case, [
            ['id' => $itemA->id, 'delivered_qty' => 4, 'delivery_order_item_id' => $lineA->id],
        ])->assertOk();

        $this->assertSame(
            'partially_delivered',
            $case->fresh()->status,
            'A case with lines still undelivered must not be marked delivered.',
        );
        $this->assertSame(4.0, round((float) $itemA->fresh()->delivered_qty, 4));
        $this->assertSame(
            0.0,
            round((float) $itemB->fresh()->delivered_qty, 4),
            'A line omitted from the request must not be recorded as delivered.',
        );

        $this->assertSame(1, BonusSchemeUsage::count(), 'Only the delivered line accrues.');
        $this->assertSame(0, BonusSchemeUsage::where('medical_case_item_id', $itemB->id)->count());
    }

    public function test_a_second_delivery_completes_the_case_when_every_line_is_fully_linked(): void
    {
        [$case, $itemA, $itemB] = $this->allocatedCase();
        $lineA = $this->completedDeliveryOrderLine($this->productA, $this->salesOrderLineA, 10);
        $lineB = $this->completedDeliveryOrderLine($this->productB, $this->salesOrderLineB, 10);

        $this->deliver($case, [
            ['id' => $itemA->id, 'delivered_qty' => 4, 'delivery_order_item_id' => $lineA->id],
        ])->assertOk();

        $response = $this->deliver($case, [
            ['id' => $itemA->id, 'delivered_qty' => 6, 'delivery_order_item_id' => $lineA->id],
            ['id' => $itemB->id, 'delivered_qty' => 10, 'delivery_order_item_id' => $lineB->id],
        ]);

        $response->assertOk();

        $this->assertSame('delivered', $case->fresh()->status, 'Every line is fully linked, so the case is delivered.');
        $this->assertSame(10.0, round((float) $itemA->fresh()->delivered_qty, 4), 'The second delivery must add to the first.');
        $this->assertSame(10.0, round((float) $itemB->fresh()->delivered_qty, 4));

        $usageA = BonusSchemeUsage::where('medical_case_item_id', $itemA->id)
            ->orderBy('id')
            ->pluck('qualifying_qty')
            ->map(fn ($qty) => round((float) $qty, 4))
            ->all();

        $this->assertSame([4.0, 6.0], $usageA, 'Each incremental delivery must accrue only its own linked quantity.');
    }

    public function test_a_delivery_order_line_cannot_back_more_than_it_shipped_across_repeated_case_deliveries(): void
    {
        [$case, $itemA] = $this->allocatedCase(allocatedA: 20);
        $line = $this->completedDeliveryOrderLine($this->productA, $this->salesOrderLineA, 10);

        $this->deliver($case, [
            ['id' => $itemA->id, 'delivered_qty' => 6, 'delivery_order_item_id' => $line->id],
        ])->assertOk();

        $response = $this->deliver($case, [
            ['id' => $itemA->id, 'delivered_qty' => 6, 'delivery_order_item_id' => $line->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.delivery_order_item_id']);
        $this->assertSame(
            6.0,
            round((float) $itemA->fresh()->delivered_qty, 4),
            'The rejected chunk must not be recorded.',
        );
        $this->assertSame('partially_delivered', $case->fresh()->status);
    }

    public function test_cumulative_delivered_quantity_cannot_exceed_allocated_across_calls(): void
    {
        [$case, $itemA] = $this->allocatedCase(allocatedA: 10);
        $line1 = $this->completedDeliveryOrderLine($this->productA, $this->salesOrderLineA, 6);
        $line2 = $this->completedDeliveryOrderLine($this->productA, $this->salesOrderLineA, 5);

        $this->deliver($case, [
            ['id' => $itemA->id, 'delivered_qty' => 6, 'delivery_order_item_id' => $line1->id],
        ])->assertOk();

        $response = $this->deliver($case, [
            ['id' => $itemA->id, 'delivered_qty' => 5, 'delivery_order_item_id' => $line2->id],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.delivered_qty']);

        $this->assertSame(6.0, round((float) $itemA->fresh()->delivered_qty, 4));
        $this->assertSame('partially_delivered', $case->fresh()->status);
        $this->assertSame(1, BonusSchemeUsage::count(), 'The rejected delivery must not accrue.');
    }

    public function test_cancelling_a_partially_delivered_case_reverses_its_usage_and_quantities(): void
    {
        [$case, $itemA] = $this->allocatedCase(allocatedA: 10);
        $line = $this->completedDeliveryOrderLine($this->productA, $this->salesOrderLineA, 4);

        $this->deliver($case, [
            ['id' => $itemA->id, 'delivered_qty' => 4, 'delivery_order_item_id' => $line->id],
        ])->assertOk();

        $this->assertSame('applied', BonusSchemeUsage::firstOrFail()->status);

        $this->actingAs($this->user)
            ->postJson('/api/v1/medical-cases/'.$case->id.'/cancel')
            ->assertOk();

        $this->assertSame('cancelled', $case->fresh()->status);
        $this->assertSame(0.0, round((float) $itemA->fresh()->delivered_qty, 4), 'A cancelled partial delivery must not keep quantities.');
        $this->assertSame('reversed', BonusSchemeUsage::firstOrFail()->fresh()->status);
    }
}
