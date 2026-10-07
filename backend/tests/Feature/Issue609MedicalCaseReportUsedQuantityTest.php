<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
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
 * Issue #609 (RP7): GET /medical-cases/reports/performance built its product
 * mix from allocated_qty — the planned quantity — so a case that reserved 10
 * units but used 4 reported 10, and a cancelled case still counted.
 *
 * The product mix must report units actually used: the case line quantity
 * recorded from a completed delivery-order line (#562), and cancelled cases
 * are not part of the report at all.
 */
class Issue609MedicalCaseReportUsedQuantityTest extends TenantTestCase
{
    private User $user;

    private Customer $hospital;

    private Product $product;

    private SalesOrderLine $salesOrderLine;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '60960960-0000-1111-2222-333333333333',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'medical_cases', 'medical_case_items', 'customers',
            'sales_order_lines', 'delivery_orders', 'delivery_order_lines',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::create([
            'name' => 'Issue609 User',
            'email' => 'issue609-'.uniqid().'@test.local',
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

        $this->hospital = Customer::factory()->create(['name' => 'Hospital 609']);
        $this->product = Product::factory()->create();

        $order = SalesOrder::create([
            'sales_order_number' => 'SO-609-'.Str::upper(Str::random(6)),
            'customer_id' => $this->hospital->id,
            'created_by' => $this->user->id,
        ]);
        $this->salesOrderLine = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Issue 609 line',
            'product_id' => $this->product->id,
        ]);
    }

    private function allocatedCase(string $date, float $allocated, string $status = 'allocated'): array
    {
        $case = MedicalCase::create([
            'customer_id' => $this->hospital->id,
            'case_date' => $date,
            'status' => $status,
            'created_by' => $this->user->id,
        ]);

        $item = MedicalCaseItem::create([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $this->salesOrderLine->id,
            'product_id' => $this->product->id,
            'allocated_qty' => $allocated,
            'delivered_qty' => 0,
            'unit_price' => 100,
        ]);

        return [$case, $item];
    }

    private function completedDeliveryOrderLine(float $deliveredQty): DeliveryOrderLine
    {
        $order = DeliveryOrder::create([
            'customer_id' => $this->hospital->id,
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
            'unit_price' => 100,
        ]);
    }

    public function test_product_mix_reports_used_quantities_not_allocations(): void
    {
        // Case 1: 10 allocated, 4 used — recorded through the real delivery
        // route from a completed DO line.
        [$usedCase, $usedItem] = $this->allocatedCase('2026-03-05', 10);
        $line = $this->completedDeliveryOrderLine(4);

        $this->actingAs($this->user)->postJson(
            '/api/v1/medical-cases/'.$usedCase->id.'/deliver',
            ['items' => [['id' => $usedItem->id, 'delivered_qty' => 4, 'delivery_order_item_id' => $line->id]]],
        )->assertOk();

        // Case 2: allocated only — nothing used yet.
        $this->allocatedCase('2026-03-10', 6);

        // Case 3: cancelled — reserved stock was given back, nothing used.
        $this->allocatedCase('2026-03-12', 5, 'cancelled');

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/medical-cases/reports/performance?from_date=2026-03-01&to_date=2026-03-31'
        );

        $response->assertOk();

        $mix = collect($response->json('data.product_mix'))->firstWhere('product_id', $this->product->id);
        $this->assertNotNull($mix);
        $this->assertSame(4.0, round((float) $mix['quantity'], 4), 'Product mix must count units used (4), not allocated (21)');

        $totals = collect($response->json('data.hospital_totals'))->keyBy('customer_id');
        $this->assertSame(2, $totals[$this->hospital->id]['case_count'], 'A cancelled case is not part of the report');
    }
}
