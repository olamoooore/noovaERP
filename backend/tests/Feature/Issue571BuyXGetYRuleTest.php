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
 * Issue #571 (BS4) — buy_x_get_y was dead code: rules could be saved without
 * X, Y or a FOC product, and accrual granted a flat foc_qty whatever the
 * scheme type. Real HTTP through the router.
 */
class Issue571BuyXGetYRuleTest extends TenantTestCase
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
            'id' => '57157157-0000-1111-2222-333333333333',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'medical_cases', 'medical_case_items', 'bonus_schemes',
            'bonus_scheme_products', 'customer_bonus_schemes', 'bonus_scheme_usages',
            'sales_order_lines', 'delivery_orders', 'delivery_order_lines',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::create([
            'name' => 'Issue 571 User',
            'email' => 'issue571-'.uniqid().'@test.local',
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
            'sales_order_number' => 'SO-571-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
        ]);
        $this->salesOrderLine = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Issue 571 line',
            'product_id' => $this->qualifyingProduct->id,
        ]);
    }

    private function schemePayload(array $rule): array
    {
        return [
            'name' => 'Issue 571 Scheme '.Str::upper(Str::random(4)),
            'start_date' => '2026-01-01',
            'status' => 'draft',
            'products' => [$rule],
        ];
    }

    private function storeScheme(array $rule)
    {
        return $this->actingAs($this->user)
            ->postJson('/api/v1/bonus-schemes', $this->schemePayload($rule));
    }

    public function test_buy_x_get_y_rule_requires_both_x_and_y(): void
    {
        $response = $this->storeScheme([
            'qualifying_product_id' => $this->qualifyingProduct->id,
            'scheme_type' => 'buy_x_get_y',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'products.0.minimum_purchase_qty',
            'products.0.foc_qty',
        ]);

        $this->assertSame(0, BonusSchemeProduct::count(), 'An incomplete buy_x_get_y rule must not be saved');
    }

    public function test_buy_x_get_y_rule_rejects_zero_x_or_y(): void
    {
        $response = $this->storeScheme([
            'qualifying_product_id' => $this->qualifyingProduct->id,
            'scheme_type' => 'buy_x_get_y',
            'minimum_purchase_qty' => 0,
            'foc_qty' => 0,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'products.0.minimum_purchase_qty',
            'products.0.foc_qty',
        ]);
    }

    public function test_buy_x_get_y_rule_defaults_the_foc_product_to_the_qualifying_product(): void
    {
        $response = $this->storeScheme([
            'qualifying_product_id' => $this->qualifyingProduct->id,
            'scheme_type' => 'buy_x_get_y',
            'minimum_purchase_qty' => 10,
            'foc_qty' => 2,
        ]);

        $response->assertCreated();

        $rule = BonusSchemeProduct::sole();
        $this->assertSame(
            (int) $this->qualifyingProduct->id,
            (int) $rule->foc_product_id,
            'Without an explicit FOC product, the qualifying product is given free',
        );
    }

    public function test_discount_rules_cannot_carry_foc_fields(): void
    {
        $fixed = $this->storeScheme([
            'qualifying_product_id' => $this->qualifyingProduct->id,
            'scheme_type' => 'fixed_discount',
            'discount_amount' => 50,
            'foc_product_id' => $this->otherProduct->id,
            'foc_qty' => 1,
        ]);
        $fixed->assertStatus(422);
        $fixed->assertJsonValidationErrors(['products.0.foc_product_id', 'products.0.foc_qty']);

        $percentage = $this->storeScheme([
            'qualifying_product_id' => $this->qualifyingProduct->id,
            'scheme_type' => 'percentage_discount',
            'discount_percentage' => 10,
            'foc_product_id' => $this->otherProduct->id,
            'foc_qty' => 1,
        ]);
        $percentage->assertStatus(422);
        $percentage->assertJsonValidationErrors(['products.0.foc_product_id', 'products.0.foc_qty']);

        $this->assertSame(0, BonusSchemeProduct::count());
    }

    public function test_discount_rules_require_their_own_amount_field(): void
    {
        $fixed = $this->storeScheme([
            'qualifying_product_id' => $this->qualifyingProduct->id,
            'scheme_type' => 'fixed_discount',
        ]);
        $fixed->assertStatus(422);
        $fixed->assertJsonValidationErrors(['products.0.discount_amount']);

        $percentage = $this->storeScheme([
            'qualifying_product_id' => $this->qualifyingProduct->id,
            'scheme_type' => 'percentage_discount',
        ]);
        $percentage->assertStatus(422);
        $percentage->assertJsonValidationErrors(['products.0.discount_percentage']);
    }

    public function test_the_create_page_empty_strings_for_irrelevant_fields_are_accepted(): void
    {
        $response = $this->storeScheme([
            'qualifying_product_id' => $this->qualifyingProduct->id,
            'scheme_type' => 'fixed_discount',
            'discount_amount' => 50,
            'discount_percentage' => '',
            'minimum_purchase_qty' => '',
            'foc_product_id' => '',
            'foc_qty' => '',
        ]);

        $response->assertCreated();

        $rule = BonusSchemeProduct::sole();
        $this->assertSame(50.0, round((float) $rule->discount_amount, 2));
        $this->assertNull($rule->foc_product_id);
        $this->assertNull($rule->foc_qty);
    }

    public function test_buy_x_get_y_rule_rejects_discount_fields(): void
    {
        $response = $this->storeScheme([
            'qualifying_product_id' => $this->qualifyingProduct->id,
            'scheme_type' => 'buy_x_get_y',
            'minimum_purchase_qty' => 10,
            'foc_qty' => 2,
            'discount_percentage' => 10,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['products.0.discount_percentage']);
    }

    public function test_update_enforces_the_same_per_type_validation(): void
    {
        $this->storeScheme([
            'qualifying_product_id' => $this->qualifyingProduct->id,
            'scheme_type' => 'buy_x_get_y',
            'minimum_purchase_qty' => 10,
            'foc_qty' => 2,
        ])->assertCreated();

        $scheme = BonusScheme::sole();

        $response = $this->actingAs($this->user)->putJson('/api/v1/bonus-schemes/'.$scheme->id, [
            'products' => [[
                'qualifying_product_id' => $this->qualifyingProduct->id,
                'scheme_type' => 'buy_x_get_y',
            ]],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'products.0.minimum_purchase_qty',
            'products.0.foc_qty',
        ]);
    }

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

    public function test_buy_x_get_y_delivery_calculates_the_free_quantity(): void
    {
        $this->storeScheme([
            'qualifying_product_id' => $this->qualifyingProduct->id,
            'scheme_type' => 'buy_x_get_y',
            'minimum_purchase_qty' => 10,
            'foc_qty' => 2,
        ])->assertCreated();

        $scheme = BonusScheme::sole();
        $scheme->update(['status' => 'active']);

        $assignment = CustomerBonusScheme::create([
            'customer_id' => $this->customer->id,
            'bonus_scheme_id' => $scheme->id,
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);

        $case = MedicalCase::create([
            'customer_id' => $this->customer->id,
            'status' => 'allocated',
            'created_by' => $this->user->id,
        ]);

        $item = MedicalCaseItem::create([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $this->salesOrderLine->id,
            'product_id' => $this->qualifyingProduct->id,
            'allocated_qty' => 25,
            'delivered_qty' => 0,
            'unit_price' => 100,
        ]);

        $line = $this->completedDeliveryOrderLine($this->qualifyingProduct, 25);

        $this->actingAs($this->user)
            ->postJson('/api/v1/medical-cases/'.$case->id.'/deliver', [
                'items' => [[
                    'id' => $item->id,
                    'delivered_qty' => 25,
                    'delivery_order_item_id' => $line->id,
                ]],
            ])
            ->assertOk();

        $usage = BonusSchemeUsage::where('medical_case_item_id', $item->id)->firstOrFail();
        $this->assertSame((int) $assignment->id, (int) $usage->customer_bonus_scheme_id);
        $this->assertSame(25.0, round((float) $usage->qualifying_qty, 4));
        $this->assertSame(
            4.0,
            round((float) $usage->foc_qty, 4),
            'Buy 10 get 2 on 25 units must earn floor(25 / 10) x 2 = 4 free units',
        );
        $this->assertSame(0.0, round((float) $usage->discount_amount, 2), 'buy_x_get_y grants no cash discount');
    }

    public function test_buy_x_get_y_delivery_below_x_earns_nothing(): void
    {
        $this->storeScheme([
            'qualifying_product_id' => $this->qualifyingProduct->id,
            'scheme_type' => 'buy_x_get_y',
            'minimum_purchase_qty' => 10,
            'foc_qty' => 2,
        ])->assertCreated();

        $scheme = BonusScheme::sole();
        $scheme->update(['status' => 'active']);

        CustomerBonusScheme::create([
            'customer_id' => $this->customer->id,
            'bonus_scheme_id' => $scheme->id,
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);

        $case = MedicalCase::create([
            'customer_id' => $this->customer->id,
            'status' => 'allocated',
            'created_by' => $this->user->id,
        ]);

        $item = MedicalCaseItem::create([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $this->salesOrderLine->id,
            'product_id' => $this->qualifyingProduct->id,
            'allocated_qty' => 9,
            'delivered_qty' => 0,
            'unit_price' => 100,
        ]);

        $line = $this->completedDeliveryOrderLine($this->qualifyingProduct, 9);

        $this->actingAs($this->user)
            ->postJson('/api/v1/medical-cases/'.$case->id.'/deliver', [
                'items' => [[
                    'id' => $item->id,
                    'delivered_qty' => 9,
                    'delivery_order_item_id' => $line->id,
                ]],
            ])
            ->assertOk();

        $this->assertSame(
            0,
            BonusSchemeUsage::where('qualifying_qty', '>', 0)->count(),
            'Below X, a buy_x_get_y rule earns no free units',
        );
    }
}
