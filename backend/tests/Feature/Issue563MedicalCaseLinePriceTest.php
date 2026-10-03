<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BonusScheme;
use App\Models\BonusSchemeProduct;
use App\Models\BonusSchemeUsage;
use App\Models\Customer;
use App\Models\CustomerBonusScheme;
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
 * Issue #563 (MC2): the case line unit price was free input
 * (`items.*.unit_price` => numeric|min:0) and the percentage reward is
 * qty x unit_price x %, so a direct API call with an inflated price earned an
 * inflated discount (the performance report used the same price).
 * is_foc lines also still accrued percentage and fixed-amount rewards.
 *
 * The case line price is the final approved price snapshot of the linked
 * sales order line (`sales_order_item_id`) — derived server-side and never
 * taken from the client. FOC lines are free of charge (spec §9 rule 8:
 * is_foc = true, unit_price = 0) and never count toward a scheme.
 *
 * Real HTTP through the router (POST /api/v1/medical-cases and
 * POST /api/v1/medical-cases/{id}/deliver), not source-string assertions.
 */
class Issue563MedicalCaseLinePriceTest extends TenantTestCase
{
    private const SO_UNIT_PRICE = 25.0;

    private const SUBMITTED_UNIT_PRICE = 9999.0;

    private User $user;

    private Customer $customer;

    private Product $product;

    private SalesOrderLine $salesOrderLine;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '56356356-0000-1111-2222-333333333333',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'medical_cases', 'medical_case_items', 'bonus_schemes',
            'bonus_scheme_products', 'customer_bonus_schemes', 'bonus_scheme_usages',
            'sales_order_lines', 'delivery_orders', 'delivery_order_lines',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::create([
            'name' => 'Issue563 User',
            'email' => 'issue563-'.uniqid().'@test.local',
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
        $this->product = Product::factory()->create();

        $order = SalesOrder::create([
            'sales_order_number' => 'SO-563-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
        ]);
        $this->salesOrderLine = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Issue 563 approved line',
            'product_id' => $this->product->id,
            'quantity' => 100,
            'unit_price' => self::SO_UNIT_PRICE,
        ]);
    }

    /**
     * @param  float|null  $discountPercentage  null for a fixed-amount rule
     */
    private function activeAssignment(?float $discountPercentage = 10, ?float $discountAmount = null): CustomerBonusScheme
    {
        $scheme = BonusScheme::create([
            'scheme_code' => 'I563-'.Str::upper(Str::random(5)),
            'name' => 'Issue 563 Scheme',
            'start_date' => '2026-01-01',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        BonusSchemeProduct::create([
            'bonus_scheme_id' => $scheme->id,
            'qualifying_product_id' => $this->product->id,
            'scheme_type' => $discountAmount !== null ? 'fixed_discount' : 'percentage_discount',
            'discount_percentage' => $discountPercentage,
            'discount_amount' => $discountAmount,
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

    private function storeItem(array $overrides = [], bool $includeUnitPrice = true)
    {
        $item = array_merge([
            'sales_order_item_id' => $this->salesOrderLine->id,
            'product_id' => $this->product->id,
            'allocated_qty' => 10,
            'is_foc' => false,
        ], $overrides);

        if ($includeUnitPrice) {
            $item['unit_price'] = self::SUBMITTED_UNIT_PRICE;
        }

        return $this->actingAs($this->user)->postJson('/api/v1/medical-cases', [
            'customer_id' => $this->customer->id,
            'items' => [$item],
        ]);
    }

    private function allocatedCaseItem(array $overrides = [], bool $includeUnitPrice = true): MedicalCaseItem
    {
        $response = $this->storeItem($overrides, $includeUnitPrice);
        $response->assertCreated();

        $item = MedicalCaseItem::where('medical_case_id', (int) $response->json('data.id'))->firstOrFail();

        $this->actingAs($this->user)->postJson(
            '/api/v1/medical-cases/'.$item->medical_case_id.'/allocate',
            ['items' => [['id' => $item->id, 'allocated_qty' => (float) $item->allocated_qty]]],
        )->assertOk();

        return $item->fresh();
    }

    private function completedDeliveryOrderLine(float $deliveredQty): DeliveryOrderLine
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
            'product_id' => $this->product->id,
            'quantity_ordered' => $deliveredQty,
            'quantity_delivered' => $deliveredQty,
            'unit_price' => self::SO_UNIT_PRICE,
        ]);
    }

    private function deliver(MedicalCaseItem $item, float $qty)
    {
        $line = $this->completedDeliveryOrderLine($qty);

        return $this->actingAs($this->user)->postJson(
            '/api/v1/medical-cases/'.$item->medical_case_id.'/deliver',
            ['items' => [[
                'id' => $item->id,
                'delivered_qty' => $qty,
                'delivery_order_item_id' => $line->id,
            ]]],
        );
    }

    public function test_store_ignores_the_submitted_unit_price_and_snapshots_the_so_line_price(): void
    {
        $response = $this->storeItem();

        $response->assertCreated();

        $item = MedicalCaseItem::where('medical_case_id', (int) $response->json('data.id'))->firstOrFail();
        $this->assertSame(
            self::SO_UNIT_PRICE,
            round((float) $item->unit_price, 2),
            'The case line price must be the linked sales order line price, not the submitted value.',
        );
    }

    public function test_store_derives_the_price_even_when_no_unit_price_is_submitted(): void
    {
        $response = $this->storeItem([], includeUnitPrice: false);

        $response->assertCreated();

        $item = MedicalCaseItem::where('medical_case_id', (int) $response->json('data.id'))->firstOrFail();
        $this->assertSame(self::SO_UNIT_PRICE, round((float) $item->unit_price, 2));
    }

    public function test_an_inflated_submitted_unit_price_cannot_inflate_the_percentage_reward(): void
    {
        $this->activeAssignment(10);
        $item = $this->allocatedCaseItem();

        $this->deliver($item, 4)->assertOk();

        $usage = BonusSchemeUsage::where('medical_case_item_id', $item->id)->firstOrFail();
        $this->assertSame(4.0, round((float) $usage->qualifying_qty, 4));
        $this->assertSame(
            10.0,
            round((float) $usage->discount_amount, 2),
            '10% of 4 x 25 (the sales order line price) — never 10% of the submitted 9999.',
        );
    }

    public function test_a_non_foc_line_receives_the_fixed_amount_reward(): void
    {
        $this->activeAssignment(null, 50.0);
        $item = $this->allocatedCaseItem();

        $this->deliver($item, 4)->assertOk();

        $usage = BonusSchemeUsage::where('medical_case_item_id', $item->id)->firstOrFail();
        $this->assertSame(50.0, round((float) $usage->discount_amount, 2));
    }

    public function test_foc_lines_are_free_of_charge_and_do_not_accrue_a_percentage_reward(): void
    {
        $this->activeAssignment(10);
        $item = $this->allocatedCaseItem(['is_foc' => true]);

        $this->assertSame(
            0.0,
            round((float) $item->unit_price, 2),
            'Spec §9 rule 8: an FOC line carries unit_price = 0.',
        );

        $this->deliver($item, 4)->assertOk();

        $this->assertSame(0, BonusSchemeUsage::count(), 'An FOC line must never count toward a scheme.');
        $this->assertSame(4.0, round((float) $item->fresh()->delivered_qty, 4), 'The FOC delivery itself is still recorded.');
    }

    public function test_foc_lines_do_not_accrue_a_fixed_amount_reward(): void
    {
        $this->activeAssignment(null, 50.0);
        $item = $this->allocatedCaseItem(['is_foc' => true]);

        $this->deliver($item, 4)->assertOk();

        $this->assertSame(0, BonusSchemeUsage::count(), 'An FOC line must not receive the fixed-amount reward either.');
    }

    public function test_store_fails_closed_when_the_linked_sales_order_line_does_not_exist(): void
    {
        $response = $this->storeItem(['sales_order_item_id' => 999999]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.sales_order_item_id']);

        $this->assertSame(0, MedicalCaseItem::count(), 'Without a linked line there is no price to snapshot.');
    }
}
