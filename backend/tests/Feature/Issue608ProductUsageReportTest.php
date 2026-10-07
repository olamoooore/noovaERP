<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\CustomerCreditNote;
use App\Models\CustomerCreditNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #608 (RPT-F2): the "Product usage" report — units used by legal
 * entity → hospital group → hospital → doctor × product, with paid vs FOC
 * units, cases and the #574 invoice-net value, drillable to the cases with
 * patient names masked unless the caller holds medical_cases.phi.view.
 *
 * Extends GET /api/v1/medical-cases/reports/performance (#185/#609/#574/#575).
 * Real HTTP through the router: router → middleware → controller.
 */
class Issue608ProductUsageReportTest extends TenantTestCase
{
    private const PHI_PERMISSION = 'medical_cases.phi.view';

    private string $tenantId;

    private int $entityA;

    private int $entityB;

    private User $manager;

    private Customer $group;

    private Customer $hospital;

    private Customer $otherHospital;

    private Customer $entityBHospital;

    private CustomerContact $doctor;

    private Product $stent;

    private Product $balloon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->assertTenantSchemaReady([
            'medical_cases', 'medical_case_items', 'sales_order_lines', 'invoices', 'invoice_lines',
            'customer_credit_notes', 'customer_credit_note_lines', 'legal_entities', 'user_entity_access',
        ]);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        foreach (['Manager', 'Viewer'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        Tenant::withoutEvents(fn () => Tenant::create([
            'id' => $this->tenantId,
            'name' => 'Product Usage Tenant',
            'email' => fake()->unique()->safeEmail(),
            'plan' => 'standard',
        ]));

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Issue 608 Group',
            'code' => 'G608-'.Str::upper(Str::random(5)),
            'base_currency' => 'USD',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->entityA = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Selling Co A', 'code' => 'E608A-'.Str::upper(Str::random(4)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->entityB = DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId, 'name' => 'Selling Co B', 'code' => 'E608B-'.Str::upper(Str::random(4)),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Manager holds medical_cases.view and medical_cases.phi.view.
        $this->manager = $this->makeUser('Manager', 'manager');

        // Hospital chain: group (top parent) → region → hospital.
        $this->group = Customer::factory()->create(['name' => 'Chain 608', 'legal_entity_id' => $this->entityA]);
        $region = Customer::factory()->create([
            'name' => 'Chain 608 North', 'parent_id' => $this->group->id, 'legal_entity_id' => $this->entityA,
        ]);
        $this->hospital = Customer::factory()->create([
            'name' => 'Hospital 608', 'parent_id' => $region->id, 'legal_entity_id' => $this->entityA,
        ]);
        $this->otherHospital = Customer::factory()->create([
            'name' => 'Standalone 608', 'legal_entity_id' => $this->entityA,
        ]);
        $this->entityBHospital = Customer::factory()->create([
            'name' => 'Entity B Hospital 608', 'legal_entity_id' => $this->entityB,
        ]);

        $this->doctor = CustomerContact::create([
            'customer_id' => $this->hospital->id,
            'name' => 'Dr. Usage',
        ]);

        $this->stent = Product::factory()->create(['name' => 'Stent 608', 'price' => 100]);
        $this->balloon = Product::factory()->create(['name' => 'Balloon 608', 'price' => 40]);
    }

    private function makeUser(string $spatieRole, string $centralRole, array $entityIds = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($spatieRole);

        DB::table('user_company_access')->insert([
            'user_id' => $user->id,
            'tenant_id' => $this->tenantId,
            'role' => $centralRole,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($entityIds as $index => $entityId) {
            DB::table('user_entity_access')->insert([
                'user_id' => $user->id,
                'legal_entity_id' => $entityId,
                'role' => 'editor',
                'is_default' => $index === 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function salesOrderLine(Customer $customer, Product $product, float $unitPrice): SalesOrderLine
    {
        $order = SalesOrder::create([
            'sales_order_number' => 'SO-608-'.Str::upper(Str::random(6)),
            'customer_id' => $customer->id,
            'created_by' => $this->manager->id,
        ]);

        return SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Issue 608 line',
            'product_id' => $product->id,
            'quantity' => 100,
            'unit_price' => $unitPrice,
        ]);
    }

    /** USD invoice line (base = document currency, rate 1). */
    private function invoiceLine(Customer $customer, Product $product, float $qty, float $unitPrice, float $discount = 0): InvoiceLine
    {
        $invoice = Invoice::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'posted',
            'currency_code' => 'USD',
            'base_currency_code' => 'USD',
            'exchange_rate' => 1,
            'created_by' => $this->manager->id,
        ]);

        return InvoiceLine::create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'description' => 'Issue 608 invoice line',
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'tax_rate' => 10,
            'tax_inclusive' => false,
            'discount_amount' => $discount,
        ]);
    }

    private function creditNote(InvoiceLine $line, float $qty, float $unitPrice): void
    {
        $creditNote = CustomerCreditNote::create([
            'credit_note_number' => 'CN-608-'.Str::upper(Str::random(6)),
            'customer_id' => $this->hospital->id,
            'invoice_id' => $line->invoice_id,
            'credit_note_date' => '2026-09-20',
            'status' => 'posted',
            'subtotal' => $qty * $unitPrice,
            'tax_amount' => 0,
            'total_amount' => $qty * $unitPrice,
            'currency_code' => 'USD',
            'exchange_rate' => 1,
            'base_currency_code' => 'USD',
            'created_by' => $this->manager->id,
        ]);

        CustomerCreditNoteLine::create([
            'credit_note_id' => $creditNote->id,
            'invoice_line_id' => $line->id,
            'product_id' => $line->product_id,
            'description' => 'Issue 608 credit',
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'tax_rate' => 0,
            'tax_inclusive' => false,
            'discount_amount' => 0,
        ]);
    }

    private function medicalCase(Customer $customer, ?CustomerContact $doctor, array $attributes = []): MedicalCase
    {
        return MedicalCase::create(array_merge([
            'customer_id' => $customer->id,
            'doctor_contact_id' => $doctor?->id,
            'case_date' => '2026-08-25',
            'procedure_date' => '2026-09-10',
            'status' => 'invoiced',
            'patient_name_encrypted' => 'Jane Patient',
            'patient_reference' => 'MRN-608-001',
            'created_by' => $this->manager->id,
        ], $attributes));
    }

    private function line(MedicalCase $case, Product $product, array $attributes): MedicalCaseItem
    {
        return MedicalCaseItem::create(array_merge([
            'medical_case_id' => $case->id,
            'sales_order_item_id' => $this->salesOrderLine($case->customer, $product, 100)->id,
            'product_id' => $product->id,
            'unit_price' => 100,
        ], $attributes));
    }

    /**
     * Case 1 (Dr. Usage, Hospital 608): stent 4 used & invoiced on a line of
     * 10 × 90 − 50 discount = 850 net, 1 unit credited (90) → 4/10 × 760 = 304;
     * plus 2 FOC stents. Case 2 (no doctor, Hospital 608): stent 3 used of 5
     * allocated, invoiced on its own 3 × 100 = 300 line. Case 3 is cancelled.
     */
    private function seedHospitalCases(): array
    {
        $line1 = $this->invoiceLine($this->hospital, $this->stent, 10, 90, 50);
        $this->creditNote($line1, 1, 90);
        $case1 = $this->medicalCase($this->hospital, $this->doctor, ['invoice_id' => $line1->invoice_id]);
        $this->line($case1, $this->stent, [
            'invoice_item_id' => $line1->id,
            'allocated_qty' => 4, 'delivered_qty' => 4, 'invoiced_qty' => 4,
        ]);
        $this->line($case1, $this->stent, [
            'allocated_qty' => 2, 'delivered_qty' => 2, 'invoiced_qty' => 0, 'unit_price' => 0, 'is_foc' => true,
        ]);

        $line2 = $this->invoiceLine($this->hospital, $this->stent, 3, 100);
        $case2 = $this->medicalCase($this->hospital, null, ['invoice_id' => $line2->invoice_id, 'patient_name_encrypted' => 'John Doe']);
        $this->line($case2, $this->stent, [
            'invoice_item_id' => $line2->id,
            'allocated_qty' => 5, 'delivered_qty' => 3, 'invoiced_qty' => 3,
        ]);

        $cancelled = $this->medicalCase($this->hospital, $this->doctor, ['status' => 'cancelled']);
        $this->line($cancelled, $this->stent, ['allocated_qty' => 9, 'delivered_qty' => 9, 'invoiced_qty' => 0]);

        return [$case1, $case2];
    }

    private function report(User $user, string $query = ''): TestResponse
    {
        return $this->actingAs($user)->getJson(
            '/api/v1/medical-cases/reports/performance?from_date=2026-09-01&to_date=2026-09-30&date_field=procedure_date'.$query
        );
    }

    public function test_units_paid_foc_cases_and_net_value_are_grouped_by_entity_group_hospital_doctor_and_product(): void
    {
        [$case1, $case2] = $this->seedHospitalCases();

        $response = $this->report($this->manager);
        $response->assertOk();

        $rows = collect($response->json('data.usage.rows'));
        $this->assertCount(2, $rows, 'One row per legal entity × group × hospital × doctor × product.');

        $doctorRow = $rows->firstWhere('doctor_contact_id', $this->doctor->id);
        $this->assertNotNull($doctorRow);
        $this->assertSame($this->entityA, (int) $doctorRow['legal_entity_id']);
        $this->assertSame('Selling Co A', $doctorRow['legal_entity_name']);
        $this->assertSame($this->group->id, (int) $doctorRow['hospital_group_id'], 'Hospital group is the top-level parent.');
        $this->assertSame('Chain 608', $doctorRow['hospital_group_name']);
        $this->assertSame($this->hospital->id, (int) $doctorRow['hospital_id']);
        $this->assertSame('Dr. Usage', $doctorRow['doctor_name']);
        $this->assertSame($this->stent->id, (int) $doctorRow['product_id']);
        $this->assertEqualsWithDelta(6.0, (float) $doctorRow['used_units'], 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $doctorRow['foc_units'], 0.0001);
        $this->assertEqualsWithDelta(4.0, (float) $doctorRow['paid_units'], 0.0001, 'paid = used − FOC');
        $this->assertSame(1, (int) $doctorRow['case_count']);
        $this->assertEqualsWithDelta(304.0, (float) $doctorRow['net_value'], 0.001);
        $this->assertSame([$case1->id], array_map('intval', $doctorRow['case_ids']));

        $unassigned = $rows->firstWhere('doctor_contact_id', null);
        $this->assertNotNull($unassigned);
        $this->assertSame('Unassigned doctor', $unassigned['doctor_name']);
        $this->assertEqualsWithDelta(3.0, (float) $unassigned['used_units'], 0.0001, 'Used = delivered, not allocated.');
        $this->assertEqualsWithDelta(3.0, (float) $unassigned['paid_units'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $unassigned['foc_units'], 0.0001);
        $this->assertEqualsWithDelta(300.0, (float) $unassigned['net_value'], 0.001);
        $this->assertSame([$case2->id], array_map('intval', $unassigned['case_ids']));

        // Hospital total for the period = sum of DO-linked case quantities and
        // linked invoice lines net of credit notes (cancelled case excluded).
        $this->assertEqualsWithDelta(9.0, $rows->where('hospital_id', $this->hospital->id)->sum('used_units'), 0.0001);
        $this->assertEqualsWithDelta(604.0, $rows->where('hospital_id', $this->hospital->id)->sum('net_value'), 0.001);
    }

    public function test_a_hospital_without_a_parent_is_its_own_group_and_filters_narrow_the_rows(): void
    {
        $this->seedHospitalCases();
        $line = $this->invoiceLine($this->otherHospital, $this->balloon, 2, 40);
        $case = $this->medicalCase($this->otherHospital, null, ['invoice_id' => $line->invoice_id]);
        $this->line($case, $this->balloon, [
            'invoice_item_id' => $line->id, 'allocated_qty' => 2, 'delivered_qty' => 2, 'invoiced_qty' => 2,
        ]);

        $rows = collect($this->report($this->manager)->assertOk()->json('data.usage.rows'));
        $standalone = $rows->firstWhere('hospital_id', $this->otherHospital->id);
        $this->assertSame($this->otherHospital->id, (int) $standalone['hospital_group_id']);
        $this->assertSame('Standalone 608', $standalone['hospital_group_name']);

        $byGroup = collect($this->report($this->manager, '&hospital_group_id='.$this->group->id)->assertOk()->json('data.usage.rows'));
        $this->assertSame([$this->hospital->id], $byGroup->pluck('hospital_id')->map(fn ($id) => (int) $id)->unique()->values()->all());

        $byProduct = collect($this->report($this->manager, '&product_id='.$this->balloon->id)->assertOk()->json('data.usage.rows'));
        $this->assertSame([$this->balloon->id], $byProduct->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->values()->all());

        $byDoctor = collect($this->report($this->manager, '&doctor_contact_id='.$this->doctor->id)->assertOk()->json('data.usage.rows'));
        $this->assertCount(1, $byDoctor);
        $this->assertSame($this->doctor->id, (int) $byDoctor->first()['doctor_contact_id']);
    }

    public function test_drill_down_cases_mask_the_patient_name_without_phi_permission(): void
    {
        [$case1] = $this->seedHospitalCases();

        $plain = collect($this->report($this->manager)->assertOk()->json('data.usage.cases'))->firstWhere('id', $case1->id);
        $this->assertSame('Jane Patient', $plain['patient_name']);
        $this->assertSame($case1->case_no, $plain['case_no']);

        // Viewer holds medical_cases.view; PHI is revoked at runtime (not a
        // seeder change) so the masked path runs through the real resolver.
        Permission::findByName(self::PHI_PERMISSION, 'web');
        Role::findByName('Viewer', 'web')->revokePermissionTo(self::PHI_PERMISSION);
        $viewer = $this->makeUser('Viewer', 'viewer');

        $response = $this->report($viewer)->assertOk();
        $this->assertTrue((bool) $response->json('data.usage.phi_masked'));
        $masked = collect($response->json('data.usage.cases'))->firstWhere('id', $case1->id);
        $this->assertNotSame('Jane Patient', $masked['patient_name']);
        $this->assertStringNotContainsString('Jane Patient', (string) $response->getContent());
        $this->assertStringNotContainsString('MRN-608-001', (string) $response->getContent());
    }

    public function test_the_report_requires_medical_cases_view(): void
    {
        $this->seedHospitalCases();
        Role::findByName('Viewer', 'web')->revokePermissionTo('medical_cases.view');
        $viewer = $this->makeUser('Viewer', 'viewer');

        $this->report($viewer)->assertForbidden();
    }

    public function test_usage_rows_and_cases_are_scoped_to_the_users_legal_entities(): void
    {
        $this->seedHospitalCases();
        $line = $this->invoiceLine($this->entityBHospital, $this->balloon, 1, 40);
        $caseB = $this->medicalCase($this->entityBHospital, null, ['invoice_id' => $line->invoice_id]);
        $this->line($caseB, $this->balloon, [
            'invoice_item_id' => $line->id, 'allocated_qty' => 1, 'delivered_qty' => 1, 'invoiced_qty' => 1,
        ]);

        $scoped = $this->makeUser('Manager', 'manager', [$this->entityA]);
        $response = $this->report($scoped)->assertOk();

        $entities = collect($response->json('data.usage.rows'))->pluck('legal_entity_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $this->assertSame([$this->entityA], $entities);
        $this->assertNull(collect($response->json('data.usage.cases'))->firstWhere('id', $caseB->id));

        // The unscoped manager sees both selling entities.
        $all = collect($this->report($this->manager)->assertOk()->json('data.usage.rows'));
        $this->assertTrue($all->contains(fn ($row) => (int) $row['legal_entity_id'] === $this->entityB && $row['legal_entity_name'] === 'Selling Co B'));
    }
}
