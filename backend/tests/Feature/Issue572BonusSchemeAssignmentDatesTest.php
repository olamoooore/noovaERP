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
 * Issue #572 (BS6) — the assignment's own dates were ignored, validity was
 * judged at today instead of the usage (case/delivery) date, and one customer
 * could hold two active assignments covering the same product. Real HTTP
 * through the router.
 */
class Issue572BonusSchemeAssignmentDatesTest extends TenantTestCase
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
            'id' => '57257257-0000-1111-2222-333333333333',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'customers', 'medical_cases', 'medical_case_items', 'bonus_schemes',
            'bonus_scheme_products', 'customer_bonus_schemes', 'bonus_scheme_usages',
            'sales_order_lines', 'delivery_orders', 'delivery_order_lines',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::create([
            'name' => 'Issue 572 User',
            'email' => 'issue572-'.uniqid().'@test.local',
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
            'sales_order_number' => 'SO-572-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
        ]);
        $this->salesOrderLine = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Issue 572 line',
            'product_id' => $this->qualifyingProduct->id,
        ]);
    }

    private function activeScheme(
        string $label,
        Product $product,
        string $startDate = '2026-01-01',
        ?string $endDate = null,
    ): BonusScheme {
        $scheme = BonusScheme::create([
            'scheme_code' => 'BS6-'.$label.'-'.Str::upper(Str::random(4)),
            'name' => 'BS6 '.$label.' Scheme',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        BonusSchemeProduct::create([
            'bonus_scheme_id' => $scheme->id,
            'qualifying_product_id' => $product->id,
            'scheme_type' => 'percentage_discount',
            'discount_percentage' => 10,
            'status' => 'active',
        ]);

        return $scheme;
    }

    private function assignViaRoute(BonusScheme $scheme, string $startDate, ?string $endDate = null)
    {
        return $this->actingAs($this->user)->postJson('/api/v1/customer-bonus-schemes', [
            'customer_id' => $this->customer->id,
            'bonus_scheme_id' => $scheme->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
    }

    private function allocatedCase(?string $caseDate, ?Product $product = null): array
    {
        $case = MedicalCase::create([
            'customer_id' => $this->customer->id,
            'status' => 'allocated',
            'case_date' => $caseDate,
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

    // ─────────────────────────────────────────────────────────────────────
    // AC1 — one active rule per customer × product at any date
    // ─────────────────────────────────────────────────────────────────────

    public function test_overlapping_assignment_for_the_same_product_is_rejected_naming_the_conflicting_scheme(): void
    {
        $first = $this->activeScheme('FIRST', $this->qualifyingProduct);
        $this->assignViaRoute($first, '2026-01-01')->assertCreated();

        $second = $this->activeScheme('SECOND', $this->qualifyingProduct);
        $response = $this->assignViaRoute($second, '2026-03-01');

        $response->assertStatus(422);
        $this->assertStringContainsString(
            $first->scheme_code,
            (string) $response->json('message'),
            'The rejection must name the conflicting scheme',
        );
        $this->assertSame(1, CustomerBonusScheme::count());
        $this->assertSame(0, CustomerBonusScheme::where('bonus_scheme_id', $second->id)->count());
    }

    public function test_sequential_assignments_for_the_same_product_are_allowed(): void
    {
        $first = $this->activeScheme('SEQ-A', $this->qualifyingProduct);
        $this->assignViaRoute($first, '2026-01-01', '2026-06-30')->assertCreated();

        $second = $this->activeScheme('SEQ-B', $this->qualifyingProduct);
        $this->assignViaRoute($second, '2026-07-01')->assertCreated();

        $this->assertSame(2, CustomerBonusScheme::count(), 'Moving between schemes with non-overlapping windows must be allowed');
    }

    public function test_overlapping_assignments_for_different_products_are_allowed(): void
    {
        $first = $this->activeScheme('DIFF-A', $this->qualifyingProduct);
        $this->assignViaRoute($first, '2026-01-01')->assertCreated();

        $second = $this->activeScheme('DIFF-B', $this->otherProduct);
        $this->assignViaRoute($second, '2026-03-01')->assertCreated();

        $this->assertSame(2, CustomerBonusScheme::count(), 'Different products may each carry their own scheme');
    }

    public function test_update_cannot_move_an_assignment_into_an_overlap(): void
    {
        $first = $this->activeScheme('UPD-A', $this->qualifyingProduct);
        $this->assignViaRoute($first, '2026-01-01', '2026-03-31')->assertCreated();

        $second = $this->activeScheme('UPD-B', $this->qualifyingProduct);
        $created = $this->assignViaRoute($second, '2026-07-01');
        $created->assertCreated();
        $assignmentId = (int) $created->json('data.id');

        $response = $this->actingAs($this->user)
            ->putJson('/api/v1/customer-bonus-schemes/'.$assignmentId, [
                'start_date' => '2026-02-01',
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString((string) $first->scheme_code, (string) $response->json('message'));
        $this->assertStringStartsWith('2026-07-01', (string) CustomerBonusScheme::find($assignmentId)->start_date);
    }

    // ─────────────────────────────────────────────────────────────────────
    // AC2 — validity evaluated at the usage (case/delivery) date
    // ─────────────────────────────────────────────────────────────────────

    public function test_back_dated_case_accrues_against_the_scheme_valid_on_its_case_date(): void
    {
        $scheme = $this->activeScheme('BACK', $this->qualifyingProduct, '2026-01-01', '2026-03-31');
        CustomerBonusScheme::create([
            'customer_id' => $this->customer->id,
            'bonus_scheme_id' => $scheme->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-03-31',
            'status' => 'active',
        ]);

        [$case, $item] = $this->allocatedCase('2026-02-15');

        $this->deliver($case, $item, 4)->assertOk();

        $usage = BonusSchemeUsage::where('medical_case_item_id', $item->id)->first();
        $this->assertNotNull(
            $usage,
            'A case dated inside the scheme window must accrue even though the scheme ended before today',
        );
        $this->assertSame(4.0, round((float) $usage->qualifying_qty, 4));
    }

    public function test_case_dated_after_the_assignment_end_does_not_accrue(): void
    {
        $scheme = $this->activeScheme('END', $this->qualifyingProduct);
        CustomerBonusScheme::create([
            'customer_id' => $this->customer->id,
            'bonus_scheme_id' => $scheme->id,
            'start_date' => '2026-01-01',
            'end_date' => '2026-05-31',
            'status' => 'active',
        ]);

        [$case, $item] = $this->allocatedCase('2026-07-01');

        $this->deliver($case, $item, 4)->assertOk();

        $this->assertSame(0, BonusSchemeUsage::count(), 'The case date is outside the assignment window');
    }

    public function test_case_dated_before_the_assignment_start_does_not_accrue(): void
    {
        $scheme = $this->activeScheme('START', $this->qualifyingProduct);
        CustomerBonusScheme::create([
            'customer_id' => $this->customer->id,
            'bonus_scheme_id' => $scheme->id,
            'start_date' => '2026-03-01',
            'status' => 'active',
        ]);

        [$case, $item] = $this->allocatedCase('2026-02-01');

        $this->deliver($case, $item, 4)->assertOk();

        $this->assertSame(0, BonusSchemeUsage::count(), 'The case date is before the assignment start');
    }
}
