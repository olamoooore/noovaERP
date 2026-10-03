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
use Modules\Sales\Models\SalesOrder as SalesOrderModel;
use Modules\Sales\Models\SalesOrderLine;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #184 — case-count bonus accrual was entirely unimplemented: delivering
 * a qualifying case item never created BonusSchemeUsage rows.
 */
class HR184MedicalCaseBonusAccrualTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $qualifyingProduct;

    private Product $otherProduct;

    private SalesOrderLine $salesOrderLine;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '99999999-0000-1111-2222-333333333333',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'medical_cases', 'medical_case_items', 'bonus_schemes',
            'bonus_scheme_products', 'customer_bonus_schemes', 'bonus_scheme_usages',
            'sales_order_lines',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::create([
            'name' => 'HR184 User',
            'email' => 'hr184-'.uniqid().'@test.local',
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
        $this->qualifyingProduct = Product::factory()->create();
        $this->otherProduct = Product::factory()->create();

        $order = SalesOrderModel::create([
            'sales_order_number' => 'SO-HR184-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
        ]);
        $this->salesOrderLine = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'HR184 line',
            'product_id' => $this->qualifyingProduct->id,
        ]);
    }

    private function activeAssignment(?float $minQty = null, ?float $discountPct = 10): CustomerBonusScheme
    {
        $scheme = BonusScheme::create([
            'scheme_code' => 'HR184-'.Str::upper(Str::random(5)),
            'name' => 'HR184 Scheme',
            'start_date' => '2026-01-01',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        BonusSchemeProduct::create([
            'bonus_scheme_id' => $scheme->id,
            'qualifying_product_id' => $this->qualifyingProduct->id,
            'scheme_type' => 'percentage_discount',
            'discount_percentage' => $discountPct,
            'minimum_purchase_qty' => $minQty,
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
            'product_id' => ($product ?? $this->qualifyingProduct)->id,
            'allocated_qty' => 10,
            'delivered_qty' => 0,
            'unit_price' => 100,
        ]);

        return [$case, $item];
    }

    /**
     * Issue #562: the deliver route only accepts usage backed by a completed
     * delivery order line for the same customer and product.
     */
    private function completedDeliveryOrderLine(Product $product, float $deliveredQty): DeliveryOrderLine
    {
        $order = DeliveryOrder::create([
            'customer_id' => $this->customer->id,
            'status' => 'done',
            'created_by' => $this->user->id,
        ]);

        return DeliveryOrderLine::create([
            'delivery_order_id' => $order->id,
            'sales_order_line_id' => $this->salesOrderLine->id,
            'line_type' => 'product',
            'product_id' => $product->id,
            'quantity_ordered' => $deliveredQty,
            'quantity_delivered' => $deliveredQty,
            'unit_price' => 100,
        ]);
    }

    private function deliver(MedicalCase $case, MedicalCaseItem $item, float $qty)
    {
        $line = $this->completedDeliveryOrderLine($item->product, $qty);

        return $this->actingAs($this->user)->postJson('/api/v1/medical-cases/'.$case->id.'/deliver', [
            'items' => [[
                'id' => $item->id,
                'delivered_qty' => $qty,
                'delivery_order_item_id' => $line->id,
            ]],
        ]);
    }

    public function test_delivery_accrues_usage_for_a_qualifying_product(): void
    {
        $assignment = $this->activeAssignment();
        [$case, $item] = $this->allocatedCase();

        $this->deliver($case, $item, 4)->assertOk();

        $usage = BonusSchemeUsage::where('medical_case_item_id', $item->id)->first();
        $this->assertNotNull($usage, 'A usage row must be accrued on delivery');
        $this->assertSame($assignment->id, (int) $usage->customer_bonus_scheme_id);
        $this->assertSame(4.0, round((float) $usage->qualifying_qty, 4));
        $this->assertSame(40.0, round((float) $usage->discount_amount, 2), '10% of 4 x 100');
        $this->assertSame('applied', $usage->status);
    }

    public function test_only_the_newly_delivered_quantity_is_accrued(): void
    {
        $this->activeAssignment();
        [$case, $item] = $this->allocatedCase();

        $this->deliver($case, $item, 4)->assertOk();

        // The API records a case as delivered once; a later incremental
        // recording is exercised directly against the accrual service.
        $item->update(['delivered_qty' => 7]);
        app(\App\Services\MedicalCaseBonusAccrualService::class)->accrueForItem($item->fresh(), 3);

        $quantities = BonusSchemeUsage::where('medical_case_item_id', $item->id)
            ->orderBy('id')
            ->pluck('qualifying_qty')
            ->map(fn ($qty) => round((float) $qty, 4))
            ->all();

        $this->assertSame([4.0, 3.0], $quantities, 'The second delivery must accrue only the 3-unit delta');
    }

    public function test_accrual_fans_out_over_every_active_assignment(): void
    {
        $this->activeAssignment();
        $this->activeAssignment();

        [$case, $item] = $this->allocatedCase();

        $this->deliver($case, $item, 2)->assertOk();

        $this->assertSame(2, BonusSchemeUsage::where('medical_case_item_id', $item->id)->count());
    }

    public function test_non_qualifying_products_do_not_accrue(): void
    {
        $this->activeAssignment();
        [$case, $item] = $this->allocatedCase($this->otherProduct);

        $this->deliver($case, $item, 4)->assertOk();

        $this->assertSame(0, BonusSchemeUsage::count());
    }

    public function test_minimum_quantity_requirement_uses_cumulative_delivered_qty(): void
    {
        $this->activeAssignment(minQty: 5);
        [$case, $item] = $this->allocatedCase();

        $this->deliver($case, $item, 2)->assertOk();
        $this->assertSame(0, BonusSchemeUsage::count(), 'Below the minimum, nothing accrues');

        $item->update(['delivered_qty' => 6]);
        app(\App\Services\MedicalCaseBonusAccrualService::class)->accrueForItem($item->fresh(), 4);

        $this->assertSame(1, BonusSchemeUsage::count());
        $this->assertSame(4.0, round((float) BonusSchemeUsage::first()->qualifying_qty, 4));
    }

    public function test_cancelling_the_case_reverses_accrued_usages(): void
    {
        $this->activeAssignment();
        [$case, $item] = $this->allocatedCase();

        $this->deliver($case, $item, 4)->assertOk();
        $this->assertSame('applied', BonusSchemeUsage::first()->status);

        $this->actingAs($this->user)
            ->postJson('/api/v1/medical-cases/'.$case->id.'/cancel')
            ->assertOk();

        $this->assertSame('reversed', BonusSchemeUsage::first()->fresh()->status);
    }
}
