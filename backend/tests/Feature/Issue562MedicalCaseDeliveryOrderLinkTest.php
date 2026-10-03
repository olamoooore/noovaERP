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
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #562 (MC1): POST /medical-cases/{id}/deliver accepted any hand-typed
 * delivered_qty and an optional delivery_order_item_id that was never checked
 * against a delivery order, product or customer — so bonus rewards accrued for
 * goods that never shipped.
 *
 * Usage may only be recorded from a completed DO line belonging to the same
 * customer and product, and the case quantity may not exceed the line's
 * delivered quantity minus quantities already linked to other cases.
 *
 * "No counter event" is asserted as "no BonusSchemeUsage row" — the only
 * reward side effect this route has today (issue #184 accrual); the dedicated
 * counter event log is issue #559.
 */
class Issue562MedicalCaseDeliveryOrderLinkTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Customer $otherCustomer;

    private Product $product;

    private Product $otherProduct;

    private SalesOrderLine $salesOrderLine;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '56256256-0000-1111-2222-333333333333',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'medical_cases', 'medical_case_items', 'bonus_schemes',
            'bonus_scheme_products', 'customer_bonus_schemes', 'bonus_scheme_usages',
            'sales_order_lines', 'delivery_orders', 'delivery_order_lines',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::create([
            'name' => 'Issue562 User',
            'email' => 'issue562-'.uniqid().'@test.local',
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
        $this->otherCustomer = Customer::factory()->create();
        $this->product = Product::factory()->create();
        $this->otherProduct = Product::factory()->create();

        $order = SalesOrder::create([
            'sales_order_number' => 'SO-562-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
        ]);
        $this->salesOrderLine = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Issue 562 line',
            'product_id' => $this->product->id,
        ]);

        $this->activeAssignment();
    }

    private function activeAssignment(): CustomerBonusScheme
    {
        $scheme = BonusScheme::create([
            'scheme_code' => 'I562-'.Str::upper(Str::random(5)),
            'name' => 'Issue 562 Scheme',
            'start_date' => '2026-01-01',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        BonusSchemeProduct::create([
            'bonus_scheme_id' => $scheme->id,
            'qualifying_product_id' => $this->product->id,
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

    private function completedDeliveryOrderLine(
        Product $product,
        float $deliveredQty,
        ?Customer $customer = null,
        string $status = 'done',
        string $lineType = 'product',
    ): DeliveryOrderLine {
        $order = DeliveryOrder::create([
            'customer_id' => ($customer ?? $this->customer)->id,
            'status' => $status,
            'created_by' => $this->user->id,
        ]);

        return DeliveryOrderLine::create([
            'delivery_order_id' => $order->id,
            'sales_order_line_id' => $this->salesOrderLine->id,
            'line_type' => $lineType,
            'product_id' => $product->id,
            'quantity_ordered' => $deliveredQty,
            'quantity_delivered' => $deliveredQty,
            'unit_price' => 100,
        ]);
    }

    private function allocatedCase(?Product $product = null): array
    {
        $case = MedicalCase::create([
            'customer_id' => $this->customer->id,
            'status' => 'allocated',
            'created_by' => $this->user->id,
        ]);

        $item = MedicalCaseItem::create([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $this->salesOrderLine->id,
            'product_id' => ($product ?? $this->product)->id,
            'allocated_qty' => 10,
            'delivered_qty' => 0,
            'unit_price' => 100,
        ]);

        return [$case, $item];
    }

    private function deliver(MedicalCase $case, MedicalCaseItem $item, float $qty, int|string|null $lineId)
    {
        $payload = ['id' => $item->id, 'delivered_qty' => $qty];

        if ($lineId !== null) {
            $payload['delivery_order_item_id'] = $lineId;
        }

        return $this->actingAs($this->user)->postJson(
            '/api/v1/medical-cases/'.$case->id.'/deliver',
            ['items' => [$payload]],
        );
    }

    public function test_deliver_without_a_delivery_order_line_is_rejected_and_accrues_nothing(): void
    {
        [$case, $item] = $this->allocatedCase();

        $response = $this->deliver($case, $item, 4, null);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.delivery_order_item_id']);

        $this->assertSame(0, BonusSchemeUsage::count(), 'No reward may accrue without a linked DO line');
        $this->assertSame(0.0, round((float) $item->fresh()->delivered_qty, 4));
        $this->assertSame('allocated', $case->fresh()->status);
    }

    public function test_deliver_with_a_line_from_another_customer_is_rejected(): void
    {
        [$case, $item] = $this->allocatedCase();
        $foreignLine = $this->completedDeliveryOrderLine($this->product, 10, $this->otherCustomer);

        $response = $this->deliver($case, $item, 4, $foreignLine->id);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.delivery_order_item_id']);

        $this->assertSame(0, BonusSchemeUsage::count());
        $this->assertSame(0.0, round((float) $item->fresh()->delivered_qty, 4));
        $this->assertSame('allocated', $case->fresh()->status);
    }

    public function test_deliver_with_a_line_for_another_product_is_rejected(): void
    {
        [$case, $item] = $this->allocatedCase();
        $wrongProductLine = $this->completedDeliveryOrderLine($this->otherProduct, 10);

        $response = $this->deliver($case, $item, 4, $wrongProductLine->id);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.delivery_order_item_id']);

        $this->assertSame(0, BonusSchemeUsage::count());
        $this->assertSame(0.0, round((float) $item->fresh()->delivered_qty, 4));
    }

    public function test_deliver_from_a_non_completed_delivery_order_is_rejected(): void
    {
        [$case, $item] = $this->allocatedCase();
        $draftLine = $this->completedDeliveryOrderLine($this->product, 10, null, 'confirmed');

        $response = $this->deliver($case, $item, 4, $draftLine->id);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.delivery_order_item_id']);

        $this->assertSame(0, BonusSchemeUsage::count());
        $this->assertSame(0.0, round((float) $item->fresh()->delivered_qty, 4));
    }

    public function test_deliver_from_a_note_line_is_rejected(): void
    {
        [$case, $item] = $this->allocatedCase();
        $noteLine = $this->completedDeliveryOrderLine($this->product, 10, null, 'done', 'note');

        $response = $this->deliver($case, $item, 4, $noteLine->id);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.delivery_order_item_id']);

        $this->assertSame(0, BonusSchemeUsage::count());
    }

    public function test_case_quantity_may_not_exceed_the_line_remaining_after_other_cases(): void
    {
        $line = $this->completedDeliveryOrderLine($this->product, 10);

        [$caseA, $itemA] = $this->allocatedCase();
        $this->deliver($caseA, $itemA, 6, $line->id)->assertOk();

        $this->assertSame(1, BonusSchemeUsage::count());

        [$caseB, $itemB] = $this->allocatedCase();
        $response = $this->deliver($caseB, $itemB, 5, $line->id);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.delivery_order_item_id']);

        $this->assertSame(1, BonusSchemeUsage::count(), 'The rejected delivery must not accrue');
        $this->assertSame(0.0, round((float) $itemB->fresh()->delivered_qty, 4));
        $this->assertSame('allocated', $caseB->fresh()->status);
    }

    public function test_linked_usage_feeds_accrual_and_shares_the_line_within_its_delivered_quantity(): void
    {
        $line = $this->completedDeliveryOrderLine($this->product, 10);

        [$caseA, $itemA] = $this->allocatedCase();
        $this->deliver($caseA, $itemA, 6, $line->id)->assertOk();

        $this->assertSame($line->id, (int) $itemA->fresh()->delivery_order_item_id);
        $usageA = BonusSchemeUsage::where('medical_case_item_id', $itemA->id)->firstOrFail();
        $this->assertSame(6.0, round((float) $usageA->qualifying_qty, 4));

        [$caseB, $itemB] = $this->allocatedCase();
        $this->deliver($caseB, $itemB, 4, $line->id)->assertOk();

        $usageB = BonusSchemeUsage::where('medical_case_item_id', $itemB->id)->firstOrFail();
        $this->assertSame(4.0, round((float) $usageB->qualifying_qty, 4));
    }
}
