<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MedicalCase;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Tests\TenantTestCase;

/**
 * Issue #568 (SALES-MED P1 / BS7): the CSV import paths wrote the patient's
 * NAME into medical_cases.patient_reference — a plain, searchable column
 * documented as a "non-identifying patient/case reference" — bypassing the
 * encryption added by #159/#222.
 *
 * Acceptance criteria → tests:
 *   AC1 Imports write the patient name to the encrypted field only;
 *       patient_reference holds a non-identifying reference
 *        → the three *_import_* tests below (DO / invoice / quotation)
 *   AC2 Migration moves existing plaintext names out of patient_reference
 *       into the encrypted field and clears the plaintext
 *        → test_backfill_migration_moves_legacy_patient_reference_names_into_the_encrypted_field
 *   AC3 After an import, the database row has no plaintext name
 *        → assertStoredRowHoldsNoPlaintextName() in each import test
 *
 * "Reproduce first": every import test goes through the real HTTP route, not
 * the service directly. On the pre-fix code the case's patient_name_encrypted
 * is null and patient_reference contains "Import Patient 568".
 */
class Issue568PatientReferencePlaintextTest extends TenantTestCase
{
    private User $user;

    private Customer $customer;

    private Product $product;

    private SalesOrderLine $salesOrderLine;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '56856856-0000-1111-2222-333333333333',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady([
            'medical_cases', 'medical_case_items', 'sales_orders',
            'sales_order_lines', 'delivery_orders', 'delivery_order_lines',
            'invoices', 'quotations',
        ]);

        $this->user = User::create([
            'name' => 'Issue568 User',
            'email' => 'issue568-'.uniqid().'@test.local',
            'password' => bcrypt('password'),
        ]);

        $this->customer = Customer::factory()->create([
            'code' => 'CUST-568',
            'name' => 'Issue 568 Customer',
        ]);
        $this->product = Product::factory()->create([
            'sku' => 'SKU-568',
            'name' => 'Issue 568 Product',
            'track_inventory' => false,
            'price' => 100,
        ]);

        $order = SalesOrder::create([
            'sales_order_number' => 'SO-568-'.Str::upper(Str::random(6)),
            'customer_id' => $this->customer->id,
            'created_by' => $this->user->id,
        ]);
        $this->salesOrderLine = SalesOrderLine::create([
            'sales_order_id' => $order->id,
            'description' => 'Issue 568 line',
            'product_id' => $this->product->id,
        ]);
    }

    private function deliveryOrderCsv(): UploadedFile
    {
        $csv = implode("\n", [
            'delivery_order_number,customer_code,product_sku,quantity,unit_price,case_no,case_tag,case_date,delivered_to,other_note,patient_name',
            'DO-568-001,CUST-568,SKU-568,4,100,1st,GEH,1/15/2026,Ward A,Imported note,Import Patient 568',
        ]);

        return UploadedFile::fake()->createWithContent('delivery-orders-568.csv', $csv);
    }

    private function invoiceCsv(): UploadedFile
    {
        $csv = implode("\n", [
            'invoice_number,status,customer_code,customer_name,invoice_date,due_date,product_sku,description,quantity,unit_price,case_no,case_tag,patient_name,delivered_to,other_note',
            'INV-568-001,draft,CUST-568,Issue 568 Customer,1/15/2026,2/15/2026,SKU-568,Issue 568 Product,3,100,1st,GEH,Import Patient 568,Ward A,Imported note',
        ]);

        return UploadedFile::fake()->createWithContent('invoices-568.csv', $csv);
    }

    private function quotationCsv(): UploadedFile
    {
        $csv = implode("\n", [
            'quotation_number,customer_code,product_sku,quantity,unit_price,case_no,case_tag,case_date,patient_name',
            'QT-568-001,CUST-568,SKU-568,3,100,1st,GEH,2026-01-15,Import Patient 568',
        ]);

        return UploadedFile::fake()->createWithContent('quotations-568.csv', $csv);
    }

    /**
     * AC3: no string column of the stored row may contain the plaintext name.
     */
    private function assertStoredRowHoldsNoPlaintextName(MedicalCase $case, string $plaintextName): void
    {
        $raw = DB::table('medical_cases')->where('id', $case->id)->value('patient_name_encrypted');

        $this->assertNotNull($raw, 'The patient name must be stored in the encrypted field');
        $this->assertNotSame($plaintextName, $raw, 'The raw encrypted field must not be plaintext');
        $this->assertSame($plaintextName, Crypt::decryptString($raw), 'The stored ciphertext must decrypt with APP_KEY');
        $this->assertSame($plaintextName, MedicalCase::find($case->id)->patient_name);

        $stored = (array) DB::table('medical_cases')->where('id', $case->id)->first();
        foreach ($stored as $column => $value) {
            if (is_string($value)) {
                $this->assertStringNotContainsString(
                    $plaintextName,
                    $value,
                    "medical_cases.{$column} must not hold the plaintext patient name"
                );
            }
        }
    }

    public function test_delivery_order_import_encrypts_the_patient_name_and_keeps_a_non_identifying_reference(): void
    {
        $response = $this
            ->withoutMiddleware()
            ->actingAs($this->user)
            ->post('/api/v1/sales/delivery-orders/import', ['file' => $this->deliveryOrderCsv()]);

        $response->assertCreated();
        $response->assertJsonPath('cases_created', 1);

        $case = MedicalCase::latest('id')->firstOrFail();

        $this->assertStoredRowHoldsNoPlaintextName($case, 'Import Patient 568');
        $this->assertSame(
            $case->case_no,
            $case->patient_reference,
            'patient_reference must hold the non-identifying case code, not the patient name'
        );
    }

    public function test_invoice_import_encrypts_the_patient_name_and_keeps_a_non_identifying_reference(): void
    {
        $response = $this
            ->withoutMiddleware()
            ->actingAs($this->user)
            ->post('/api/v1/invoices/import', ['file' => $this->invoiceCsv()]);

        $response->assertStatus(200);
        $response->assertJsonPath('created', 1);
        $response->assertJsonPath('errors', []);

        $case = MedicalCase::latest('id')->firstOrFail();

        $this->assertStoredRowHoldsNoPlaintextName($case, 'Import Patient 568');
        $this->assertSame($case->case_no, $case->patient_reference);
    }

    public function test_quotation_import_encrypts_the_patient_name_and_keeps_a_non_identifying_reference(): void
    {
        $response = $this
            ->withoutMiddleware()
            ->actingAs($this->user)
            ->post('/api/v1/quotations/import', ['file' => $this->quotationCsv()]);

        $response->assertStatus(200);
        $response->assertJsonPath('created', 1);
        $response->assertJsonPath('errors', []);

        $case = MedicalCase::latest('id')->firstOrFail();

        $this->assertStoredRowHoldsNoPlaintextName($case, 'Import Patient 568');
        $this->assertSame($case->case_no, $case->patient_reference);
    }

    public function test_backfill_migration_moves_legacy_patient_reference_names_into_the_encrypted_field(): void
    {
        $legacyId = DB::table('medical_cases')->insertGetId([
            'case_no' => 'MC-568-LEGACY',
            'customer_id' => $this->customer->id,
            'patient_reference' => 'Legacy Patient 568',
            'patient_name_encrypted' => null,
            'status' => 'draft',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migrationFile = collect(glob(base_path('database/migrations/tenant/*.php')))
            ->first(fn (string $file) => str_contains((string) file_get_contents($file), '#568'));

        $this->assertNotNull($migrationFile, 'The issue #568 backfill migration must exist');

        $migration = require $migrationFile;
        $migration->up();

        $row = DB::table('medical_cases')->where('id', $legacyId)->first();

        $this->assertSame(
            'Legacy Patient 568',
            Crypt::decryptString((string) $row->patient_name_encrypted),
            'The plaintext reference must be moved into the encrypted field'
        );
        $this->assertSame(
            'MC-568-LEGACY',
            $row->patient_reference,
            'patient_reference must be replaced by the non-identifying case code'
        );

        // Idempotent: a second run must not double-encrypt or change the row.
        $migration->up();
        $again = DB::table('medical_cases')->where('id', $legacyId)->first();

        $this->assertSame($row->patient_name_encrypted, $again->patient_name_encrypted, 'Re-running the backfill must be a no-op');
        $this->assertSame('MC-568-LEGACY', $again->patient_reference);
    }
}
