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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TenantTestCase;

/**
 * Issue #564 (SALES-MED P0 / BS3): medical-case imports.
 *
 * Acceptance criteria → tests:
 *   AC1 Imports record usage (from DO lines when linked)
 *        → test_do_import_records_bonus_usage_from_its_delivery_order_line
 *        → test_invoice_import_records_bonus_usage_for_its_case
 *   AC2 Tags are stored as case tags only; no automatic scheme creation or
 *       assignment
 *        → test_do_import_creates_the_case_with_its_tag_but_never_creates_or_assigns_an_auto_scheme
 *        → test_quotation_import_stores_the_tag_and_records_no_usage_while_the_quotation_is_draft
 *   AC3 Imported FOC lines are matched to entitlements as redemptions (#561)
 *        → test_imported_foc_line_is_matched_as_a_redemption_against_an_active_entitlement
 *        → test_imported_foc_line_without_any_matching_entitlement_records_nothing
 *   AC4 Clean-up script: list `Auto: *` schemes with no rules + assignments
 *        → test_auto_scheme_report_lists_only_ruleless_auto_schemes_with_their_assignments
 *        → test_list_auto_bonus_schemes_command_is_registered_and_runs_read_only
 *
 * "Reproduce first": every test goes through the real entry point (the HTTP
 * import route, or the artisan command for AC4's list-only script) — no
 * direct service instantiation for the import paths. On current main the
 * suite fails because
 *   (a) the DO import's header insert writes `_case_no`/`patient_name`
 *       columns that do not exist on `delivery_orders`, so any Case-Tracking
 *       CSV rolls the whole group back (0 cases, 0 items),
 *   (b) no import path ever calls the accrual service, so imported usage
 *       never produces a `bonus_scheme_usages` row, and
 *   (c) every case tag calls `resolveOrCreateBonusScheme`, creating an
 *       active `Auto: <tag>` scheme with no rules and auto-assigning it.
 *
 * Bonus-counter note: AC1/AC3 reference BonusCounterService (#559) and the
 * replay/redemption flow (#561), both of which are still OPEN issues — no
 * such classes exist yet. Until they land, usage is recorded through the
 * live shared accrual service (`MedicalCaseBonusAccrualService`:
 * accrueForItem() for qualifying usage, recordFocRedemption() for FOC),
 * with `CaseImportService::recordUsageForCase()` as the single import seam
 * that #559 can take over.
 */
class Issue564MedicalCaseImportTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $product;

    private SalesOrderLine $salesOrderLine;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '56456456-0000-1111-2222-333333333333',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'medical_cases', 'medical_case_items', 'bonus_schemes',
            'bonus_scheme_products', 'customer_bonus_schemes', 'bonus_scheme_usages',
            'sales_orders', 'sales_order_lines', 'delivery_orders', 'delivery_order_lines',
            'invoices', 'quotations',
        ]);

        $this->user = User::create([
            'name' => 'Issue564 User',
            'email' => 'issue564-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);

        $this->customer = Customer::factory()->create([
            'code' => 'CUST-564',
            'name' => 'Issue 564 Customer',
        ]);
        $this->product = Product::factory()->create([
            'sku' => 'SKU-564',
            'name' => 'Issue 564 Product',
            'track_inventory' => false,
            'price' => 100,
        ]);

        $order = SalesOrder::create([
            'sales_order_number' => 'SO-564-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
        ]);
        $this->salesOrderLine = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Issue 564 line',
            'product_id' => $this->product->id,
        ]);
    }

    /**
     * Active customer assignment whose scheme has one qualifying-product rule
     * for $product (mirrors the Issue562/#184 fixture).
     */
    private function activeAssignment(float $focQty = 1): CustomerBonusScheme
    {
        $scheme = BonusScheme::create([
            'scheme_code' => 'I564-'.Str::upper(Str::random(5)),
            'name' => 'Issue 564 Scheme',
            'start_date' => '2026-01-01',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        BonusSchemeProduct::create([
            'bonus_scheme_id' => $scheme->id,
            'qualifying_product_id' => $this->product->id,
            'scheme_type' => 'percentage_discount',
            'discount_percentage' => 10,
            'foc_qty' => $focQty,
            'status' => 'active',
        ]);

        return CustomerBonusScheme::create([
            'customer_id' => $this->customer->id,
            'bonus_scheme_id' => $scheme->id,
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);
    }

    private function doCaseCsv(string $tag, string $caseNo = '1st'): UploadedFile
    {
        $csv = implode("\n", [
            'delivery_order_number,customer_code,product_sku,quantity,unit_price,case_no,case_tag,case_date,delivered_to,other_note,patient_name',
            "DO-564-001,CUST-564,SKU-564,4,100,{$caseNo},{$tag},1/15/2026,Ward A,Imported note,Import Patient",
        ]);

        return UploadedFile::fake()->createWithContent('delivery-orders.csv', $csv);
    }

    private function importDo(UploadedFile $file)
    {
        return $this
            ->withoutMiddleware()
            ->actingAs($this->user)
            ->post('/api/v1/sales/delivery-orders/import', ['file' => $file]);
    }

    private function invoiceCaseCsv(string $tag): UploadedFile
    {
        $csv = implode("\n", [
            'invoice_number,status,customer_code,customer_name,invoice_date,due_date,product_sku,description,quantity,unit_price,case_no,case_tag,patient_name,delivered_to,other_note',
            'INV-564-001,draft,CUST-564,Issue 564 Customer,1/15/2026,2/15/2026,SKU-564,Issue 564 Product,3,100,1st,'
            .$tag.',Import Patient,Ward A,Imported note',
        ]);

        return UploadedFile::fake()->createWithContent('invoices.csv', $csv);
    }

    private function quotationCaseCsv(string $tag): UploadedFile
    {
        $csv = implode("\n", [
            'quotation_number,customer_code,product_sku,quantity,unit_price,case_no,case_tag,case_date,patient_name',
            "QT-564-001,CUST-564,SKU-564,3,100,1st,{$tag},2026-01-15,Import Patient",
        ]);

        return UploadedFile::fake()->createWithContent('quotations.csv', $csv);
    }

    // ─────────────────────────────────────────────────────────────────────
    // AC2 — tags are stored as case tags only (no auto scheme/assignment)
    // ─────────────────────────────────────────────────────────────────────

    public function test_do_import_creates_the_case_with_its_tag_but_never_creates_or_assigns_an_auto_scheme(): void
    {
        $response = $this->importDo($this->doCaseCsv('HTx 60s'));

        $response->assertCreated();
        $response->assertJsonPath('created', 1);
        $response->assertJsonPath('cases_created', 1);
        $response->assertJsonPath('errors', []);

        $case = MedicalCase::latest('id')->firstOrFail();
        $this->assertSame('HTx 60s', $case->case_tag);
        $this->assertSame((int) $this->customer->id, (int) $case->customer_id);
        $this->assertSame('delivered', $case->status);
        // Issue #568 (BS7): the imported name is PHI — encrypted at rest, not
        // in the searchable patient_reference, which keeps the case code.
        $this->assertSame('Import Patient', $case->patient_name);
        $this->assertSame($case->case_no, $case->patient_reference);

        $item = MedicalCaseItem::latest('id')->firstOrFail();
        $this->assertSame((int) $case->id, (int) $item->medical_case_id);
        $this->assertSame((int) $this->salesOrderLine->id, (int) $item->sales_order_item_id);
        $this->assertSame(4.0, round((float) $item->delivered_qty, 4));
        $this->assertFalse($item->is_foc);

        // The tag must NOT produce an `Auto: …` scheme or any customer
        // assignment (the pre-fix behaviour of resolveOrCreateBonusScheme).
        $this->assertDatabaseMissing('bonus_schemes', ['name' => 'Auto: HTx 60s']);
        $this->assertSame(0, CustomerBonusScheme::count(), 'A case tag must not auto-assign a scheme');
    }

    // ─────────────────────────────────────────────────────────────────────
    // AC1 — imports record usage, from DO lines when linked
    // ─────────────────────────────────────────────────────────────────────

    public function test_do_import_records_bonus_usage_from_its_delivery_order_line(): void
    {
        $assignment = $this->activeAssignment();

        $response = $this->importDo($this->doCaseCsv('GEH'));

        $response->assertCreated();
        $response->assertJsonPath('cases_created', 1);

        $item = MedicalCaseItem::latest('id')->firstOrFail();
        $this->assertNotNull($item->delivery_order_item_id, 'The imported case item must link its DO line');
        $this->assertNotNull($item->sales_order_item_id);

        $this->assertDatabaseCount('bonus_scheme_usages', 1);
        $usage = BonusSchemeUsage::sole();
        $this->assertSame((int) $assignment->id, (int) $usage->customer_bonus_scheme_id);
        $this->assertSame((int) $item->id, (int) $usage->medical_case_item_id);
        $this->assertSame((int) $item->sales_order_item_id, (int) $usage->sales_order_item_id);
        $this->assertSame(4.0, round((float) $usage->qualifying_qty, 4));
        $this->assertSame('applied', $usage->status);
    }

    public function test_invoice_import_records_bonus_usage_for_its_case(): void
    {
        $assignment = $this->activeAssignment();

        $response = $this
            ->withoutMiddleware()
            ->actingAs($this->user)
            ->post('/api/v1/invoices/import', ['file' => $this->invoiceCaseCsv('Clinic')]);

        $response->assertStatus(200);
        $response->assertJsonPath('created', 1);
        $response->assertJsonPath('errors', []);

        $case = MedicalCase::latest('id')->firstOrFail();
        $this->assertSame('Clinic', $case->case_tag);
        $this->assertNotNull($case->invoice_id);

        $item = MedicalCaseItem::latest('id')->firstOrFail();
        $this->assertSame((int) $case->id, (int) $item->medical_case_id);
        $this->assertNotNull($item->sales_order_item_id, 'The imported case item must link a sales order line');
        $this->assertSame(3.0, round((float) $item->invoiced_qty, 4));

        $this->assertDatabaseCount('bonus_scheme_usages', 1);
        $usage = BonusSchemeUsage::sole();
        $this->assertSame((int) $assignment->id, (int) $usage->customer_bonus_scheme_id);
        $this->assertSame((int) $item->id, (int) $usage->medical_case_item_id);
        $this->assertSame(3.0, round((float) $usage->qualifying_qty, 4));
        $this->assertSame('applied', $usage->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // AC2 — quotation import: tag only; spec §6.5 forbids consuming an
    // entitlement while the quotation is still draft
    // ─────────────────────────────────────────────────────────────────────

    public function test_quotation_import_stores_the_tag_and_records_no_usage_while_the_quotation_is_draft(): void
    {
        $response = $this
            ->withoutMiddleware()
            ->actingAs($this->user)
            ->post('/api/v1/quotations/import', ['file' => $this->quotationCaseCsv('Clinic')]);

        $response->assertStatus(200);
        $response->assertJsonPath('created', 1);
        $response->assertJsonPath('errors', []);

        $case = MedicalCase::latest('id')->firstOrFail();
        $this->assertSame('Clinic', $case->case_tag);
        $this->assertSame('draft', $case->status);
        $this->assertSame(1, MedicalCaseItem::count());

        $this->assertDatabaseMissing('bonus_schemes', ['name' => 'Auto: Clinic']);
        $this->assertSame(0, CustomerBonusScheme::count());
        $this->assertSame(
            0,
            BonusSchemeUsage::count(),
            'A draft quotation must not consume scheme entitlement (spec §6.5)'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // AC3 — imported FOC lines are matched to entitlements as redemptions
    // ─────────────────────────────────────────────────────────────────────

    public function test_imported_foc_line_is_matched_as_a_redemption_against_an_active_entitlement(): void
    {
        $assignment = $this->activeAssignment();

        $response = $this->importDo($this->doCaseCsv('FOC'));

        $response->assertCreated();
        $response->assertJsonPath('cases_created', 1);

        $item = MedicalCaseItem::latest('id')->firstOrFail();
        $this->assertTrue($item->is_foc, 'The FOC tag marks the imported line as FOC');
        $this->assertSame(0.0, round((float) $item->unit_price, 4));

        // A FOC line never counts as qualifying usage …
        $this->assertSame(
            0,
            BonusSchemeUsage::query()->where('qualifying_qty', '>', 0)->count(),
            'A FOC line must not count toward the qualifying counter'
        );

        // … it is recorded as a redemption against the customer's entitlement.
        $this->assertDatabaseCount('bonus_scheme_usages', 1);
        $redemption = BonusSchemeUsage::sole();
        $this->assertSame((int) $assignment->id, (int) $redemption->customer_bonus_scheme_id);
        $this->assertSame((int) $item->id, (int) $redemption->medical_case_item_id);
        $this->assertSame(0.0, round((float) $redemption->qualifying_qty, 4));
        $this->assertSame(4.0, round((float) $redemption->foc_qty, 4));
        $this->assertSame('applied', $redemption->status);
    }

    public function test_imported_foc_line_without_any_matching_entitlement_records_nothing(): void
    {
        $response = $this->importDo($this->doCaseCsv('FOC'));

        $response->assertCreated();
        $response->assertJsonPath('cases_created', 1);

        $case = MedicalCase::latest('id')->firstOrFail();
        $this->assertSame('FOC', $case->case_tag);
        $item = MedicalCaseItem::latest('id')->firstOrFail();
        $this->assertTrue($item->is_foc);

        // Nothing to match against → no usage row, no invented scheme,
        // no invented assignment.
        $this->assertSame(0, BonusSchemeUsage::count());
        $this->assertDatabaseMissing('bonus_schemes', ['name' => 'Auto: FOC']);
        $this->assertSame(0, CustomerBonusScheme::count());
    }

    // ─────────────────────────────────────────────────────────────────────
    // AC4 — read-only clean-up report: `Auto: *` schemes with no rules
    // ─────────────────────────────────────────────────────────────────────

    public function test_auto_scheme_report_lists_only_ruleless_auto_schemes_with_their_assignments(): void
    {
        // Rule-less Auto scheme WITH an assignment → must be listed.
        $autoWithAssignment = BonusScheme::create([
            'scheme_code' => 'AUTO-A-'.Str::upper(Str::random(4)),
            'start_date' => '2026-01-01',
            'name' => 'Auto: FOC',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
        $assignment = CustomerBonusScheme::create([
            'customer_id' => $this->customer->id,
            'bonus_scheme_id' => $autoWithAssignment->id,
            'start_date' => '2026-01-01',
            'status' => 'active',
        ]);

        // Rule-less Auto scheme WITHOUT an assignment → must be listed too.
        $autoWithoutAssignment = BonusScheme::create([
            'scheme_code' => 'AUTO-B-'.Str::upper(Str::random(4)),
            'start_date' => '2026-01-01',
            'name' => 'Auto: Empty',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        // Auto scheme WITH a rule → has real configuration; not clean-up bait.
        $autoWithRule = BonusScheme::create([
            'scheme_code' => 'AUTO-C-'.Str::upper(Str::random(4)),
            'start_date' => '2026-01-01',
            'name' => 'Auto: GEH',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
        BonusSchemeProduct::create([
            'bonus_scheme_id' => $autoWithRule->id,
            'qualifying_product_id' => $this->product->id,
            'scheme_type' => 'percentage_discount',
            'discount_percentage' => 10,
            'status' => 'active',
        ]);

        // Non-Auto scheme with no rules → operator-managed; out of scope.
        BonusScheme::create([
            'scheme_code' => 'NOPREFIX-'.Str::upper(Str::random(4)),
            'start_date' => '2026-01-01',
            'name' => 'Legacy Promo',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $schemesBefore = BonusScheme::count();
        $assignmentsBefore = CustomerBonusScheme::count();

        $rows = app(\App\Services\AutoBonusSchemeReport::class)->rows();

        $this->assertSame(
            [$autoWithAssignment->id, $autoWithoutAssignment->id],
            array_column($rows, 'scheme_id'),
            'Only Auto: * schemes with zero rules must be listed, in scheme order'
        );

        $listed = collect($rows)->firstWhere('scheme_id', $autoWithAssignment->id);
        $this->assertSame(1, $listed['assignment_count']);
        $this->assertSame((int) $this->customer->id, (int) $listed['assignments'][0]['customer_id']);
        $this->assertSame((int) $assignment->id, (int) $listed['assignments'][0]['id']);

        $empty = collect($rows)->firstWhere('scheme_id', $autoWithoutAssignment->id);
        $this->assertSame(0, $empty['assignment_count']);

        // List-only guarantee: the report changes nothing.
        $this->assertSame($schemesBefore, BonusScheme::count());
        $this->assertSame($assignmentsBefore, CustomerBonusScheme::count());
        $this->assertSame(0, BonusSchemeUsage::count());
    }

    public function test_list_auto_bonus_schemes_command_is_registered_and_runs_read_only(): void
    {
        $commands = Artisan::all();
        $this->assertArrayHasKey('bonus-schemes:list-auto', $commands);

        $definition = $commands['bonus-schemes:list-auto']->getDefinition();
        $this->assertTrue($definition->hasOption('tenant'));
        $this->assertTrue($definition->hasOption('format'));

        BonusScheme::create([
            'scheme_code' => 'AUTO-D-'.Str::upper(Str::random(4)),
            'start_date' => '2026-01-01',
            'name' => 'Auto: FOC',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
        $schemesBefore = BonusScheme::count();
        $assignmentsBefore = CustomerBonusScheme::count();

        // Test tenants have no per-tenant schema, so forEachTenant skips
        // them (no $tenant->run() inside a test transaction) — the command
        // must still exit cleanly and, being list-only, mutate nothing.
        $exit = Artisan::call('bonus-schemes:list-auto');

        $this->assertSame(0, $exit);
        $this->assertSame($schemesBefore, BonusScheme::count());
        $this->assertSame($assignmentsBefore, CustomerBonusScheme::count());
        $this->assertSame(0, BonusSchemeUsage::count());
    }
}
