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
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #185 — hospital/doctor performance reporting for medical cases.
 */
class HR185MedicalCasePerformanceReportTest extends TenantTestCase
{
    private User $user;

    private Product $product;

    private SalesOrderLine $salesOrderLine;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '88888888-9999-0000-1111-222222222222',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'medical_cases', 'medical_case_items', 'customers',
            'customer_contacts', 'bonus_scheme_usages', 'sales_order_lines',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->user = User::create([
            'name' => 'HR185 User',
            'email' => 'hr185-'.uniqid().'@test.local',
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

        $this->product = Product::factory()->create();
    }

    private function doctorContact(Customer $customer, string $name): int
    {
        return (int) DB::table('customer_contacts')->insertGetId([
            'customer_id' => $customer->id,
            'name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function salesOrderLine(Customer $customer): SalesOrderLine
    {
        $order = SalesOrder::create([
            'sales_order_number' => 'SO-HR185-'.Str::upper(Str::random(6)),
            'customer_id' => $customer->id,
            'created_by' => $this->user->id,
        ]);

        return SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'HR185 line',
            'product_id' => $this->product->id,
        ]);
    }

    private function caseWithItem(Customer $customer, ?int $doctorId, string $date, float $qty, float $unitPrice): MedicalCase
    {
        $case = MedicalCase::create([
            'customer_id' => $customer->id,
            'doctor_contact_id' => $doctorId,
            'case_date' => $date,
            'status' => 'invoiced',
            'created_by' => $this->user->id,
        ]);

        MedicalCaseItem::create([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $this->salesOrderLine($customer)->id,
            'product_id' => $this->product->id,
            'allocated_qty' => $qty,
            'delivered_qty' => $qty,
            'invoiced_qty' => $qty,
            'unit_price' => $unitPrice,
        ]);

        return $case;
    }

    public function test_performance_report_groups_by_hospital_doctor_month_and_product(): void
    {
        $hospitalA = Customer::factory()->create(['name' => 'Hospital A']);
        $hospitalB = Customer::factory()->create(['name' => 'Hospital B']);

        $doctorA = $this->doctorContact($hospitalA, 'Dr. Alpha');
        $doctorB = $this->doctorContact($hospitalB, 'Dr. Beta');

        // Hospital A: two cases in Jan (one with a bonus usage), one in Feb.
        $caseA1 = $this->caseWithItem($hospitalA, $doctorA, '2026-01-05', 2, 100);
        $this->caseWithItem($hospitalA, $doctorA, '2026-01-20', 1, 100);
        $this->caseWithItem($hospitalA, $doctorA, '2026-02-10', 3, 100);

        // Hospital B: one case in Jan.
        $this->caseWithItem($hospitalB, $doctorB, '2026-01-15', 4, 50);

        // Accrue a bonus usage against one item of caseA1.
        $item = MedicalCaseItem::where('medical_case_id', $caseA1->id)->first();
        $scheme = BonusScheme::create([
            'scheme_code' => 'HR185-'.Str::upper(Str::random(5)),
            'name' => 'HR185 Scheme',
            'start_date' => '2026-01-01',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
        $assignment = CustomerBonusScheme::create([
            'customer_id' => $hospitalA->id,
            'bonus_scheme_id' => $scheme->id,
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
        BonusSchemeProduct::create([
            'bonus_scheme_id' => $scheme->id,
            'qualifying_product_id' => $this->product->id,
            'scheme_type' => 'percentage_discount',
            'status' => 'active',
        ]);
        BonusSchemeUsage::create([
            'customer_bonus_scheme_id' => $assignment->id,
            'sales_order_item_id' => $item->sales_order_item_id,
            'medical_case_item_id' => $item->id,
            'qualifying_qty' => 2,
            'discount_amount' => 0,
            'foc_qty' => 0,
            'status' => 'applied',
        ]);

        $response = $this->actingAs($this->user)->getJson(
            '/api/v1/medical-cases/reports/performance?from_date=2026-01-01&to_date=2026-02-28'
        );

        $response->assertOk();

        $hospitalTotals = collect($response->json('data.hospital_totals'))->keyBy('customer_id');
        $this->assertSame(3, $hospitalTotals[$hospitalA->id]['case_count']);
        $this->assertSame(600.0, (float) $hospitalTotals[$hospitalA->id]['invoiced_value']);
        $this->assertSame(1, $hospitalTotals[$hospitalA->id]['bonus_case_count']);
        $this->assertSame(1, $hospitalTotals[$hospitalB->id]['case_count']);
        $this->assertSame(200.0, (float) $hospitalTotals[$hospitalB->id]['invoiced_value']);

        // Monthly rows within the range.
        $hospitalAMonths = collect($response->json('data.by_hospital'))
            ->where('customer_id', $hospitalA->id)
            ->pluck('month')
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['2026-01', '2026-02'], $hospitalAMonths);

        // Doctors are per customer_contacts row, not rolled up by person.
        // by_doctor rows are per contact row AND month.
        $doctorRows = collect($response->json('data.by_doctor'));
        $this->assertSame(3, $doctorRows->where('doctor_contact_id', $doctorA)->sum('case_count'));
        $this->assertSame(1, $doctorRows->where('doctor_contact_id', $doctorB)->sum('case_count'), 'Dr. Beta at Hospital B is a separate line');
        $this->assertSame(
            $hospitalA->id,
            (int) $doctorRows->firstWhere('doctor_contact_id', $doctorA)['customer_id']
        );

        // Product mix aggregates quantities.
        $mix = collect($response->json('data.product_mix'))->firstWhere('product_id', $this->product->id);
        $this->assertNotNull($mix);
        $this->assertSame(10.0, round((float) $mix['quantity'], 4));
    }

    public function test_report_validates_the_date_range(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/medical-cases/reports/performance?from_date=2026-02-01&to_date=2026-01-01')
            ->assertStatus(422);
    }
}
