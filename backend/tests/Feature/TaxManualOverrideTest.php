<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TaxDeterminationService;
use App\Services\TaxOverrideService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * #277 F5: manual tax override — mandatory reason, independent approval for
 * high-risk transitions, audit with engine vs applied + delta, report.
 */
class TaxManualOverrideTest extends TenantTestCase
{
    private User $user;

    private int $approverId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test']);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id, 'tenant_id' => $tenant->id, 'role' => 'admin',
            'is_default' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($this->user);

        $this->approverId = (int) User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@taxfix.test'])->id;
    }

    public function test_reason_is_mandatory_and_validated(): void
    {
        $service = app(TaxOverrideService::class);

        try {
            $service->guardAndRecordDocumentOverride('invoice', 1, ['treatment' => 'standard_rated'], ['treatment' => 'exempt']);
            $this->fail('A reason-less override must be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('TAX_OVERRIDE_REASON_REQUIRED', $e->getMessage());
        }

        try {
            $service->guardAndRecordDocumentOverride('invoice', 1, ['treatment' => 'standard_rated'], ['treatment' => 'exempt', 'reason_code' => 'MADE_UP']);
            $this->fail('An unknown reason code must be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('TAX_OVERRIDE_REASON_INVALID', $e->getMessage());
        }

        // No difference = no override recorded.
        $this->assertNull($service->guardAndRecordDocumentOverride('invoice', 1, ['treatment' => 'standard_rated'], ['treatment' => 'standard_rated']));
        $this->assertSame(0, DB::table('tax_override_audits')->count());
    }

    public function test_high_risk_override_requires_independent_approver(): void
    {
        $service = app(TaxOverrideService::class);
        $engine = ['treatment' => 'standard_rated', 'jurisdiction' => 'SG'];

        try {
            $service->guardAndRecordDocumentOverride('invoice', 1, $engine, ['treatment' => 'exempt', 'reason_code' => 'EXEMPT_SUPPLY']);
            $this->fail('High-risk override without an approver must be blocked.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('TAX_OVERRIDE_APPROVER_REQUIRED', $e->getMessage());
        }

        try {
            $service->guardAndRecordDocumentOverride('invoice', 1, $engine, ['treatment' => 'exempt', 'reason_code' => 'EXEMPT_SUPPLY', 'approver_id' => $this->user->id]);
            $this->fail('Self-approval must be blocked.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('TAX_OVERRIDE_SELF_APPROVAL', $e->getMessage());
        }

        $row = $service->guardAndRecordDocumentOverride(
            'invoice', 1, $engine,
            ['treatment' => 'exempt', 'reason_code' => 'EXEMPT_SUPPLY', 'approver_id' => $this->approverId],
            9.0, 0.0,
        );

        $this->assertNotNull($row);
        $this->assertTrue((bool) $row['high_risk']);
        $this->assertSame($this->approverId, (int) $row['approver_id']);
        $this->assertSame(-9.0, (float) $row['delta_tax_amount']);
    }

    public function test_low_risk_override_records_engine_vs_applied_and_report_reproduces_delta(): void
    {
        $service = app(TaxOverrideService::class);

        $service->guardAndRecordDocumentOverride(
            'bill', 42,
            ['jurisdiction' => 'SG', 'tax_regime' => 'GST'],
            ['jurisdiction' => 'MY', 'tax_regime' => 'SST', 'reason_code' => 'CONTRACT_TERMS', 'reason_text' => 'Cross-border service'],
            9.0, 8.0,
        );

        $report = $service->report(reasonCode: 'CONTRACT_TERMS');
        $this->assertCount(1, $report);
        $this->assertSame('bill', $report[0]['document_type']);
        $this->assertSame(42, $report[0]['document_id']);
        $this->assertSame(-1.0, $report[0]['delta_tax_amount']);
        $this->assertSame('MY', $report[0]['applied_values']['jurisdiction']);
        $this->assertSame('SG', $report[0]['engine_values']['jurisdiction']);

        // Filters exclude non-matching rows.
        $this->assertCount(0, $service->report(reasonCode: 'AUTHORITY_RULING'));
        $this->assertCount(0, $service->report(documentType: 'invoice'));

        $this->actingAs($this->user)->getJson('/api/v1/accounting/tax-report/overrides?reason_code=CONTRACT_TERMS')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_determination_with_header_override_records_the_audit(): void
    {
        $customer = Customer::create(['name' => 'OVR Cust '.Str::random(4), 'is_active' => true]);
        $invoice = Invoice::create([
            'customer_id' => $customer->id, 'invoice_number' => 'OVR-'.Str::upper(Str::random(6)),
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'status' => 'draft', 'lifecycle_status' => 'draft',
            'subtotal' => 0, 'tax_amount' => 0, 'discount_amount' => 0, 'total' => 0,
            'paid_amount' => 0, 'balance' => 0, 'created_by' => $this->user->id,
        ]);

        app(TaxDeterminationService::class)->determineForInvoice($invoice, [
            'treatment' => 'exempt',
            'reason_code' => 'EXEMPT_SUPPLY',
            'approver_id' => $this->approverId,
        ]);

        $audit = DB::table('tax_override_audits')->where('document_type', 'invoice')->where('document_id', $invoice->id)->first();
        $this->assertNotNull($audit, 'The determination path must record the override audit.');
        $this->assertSame('EXEMPT_SUPPLY', $audit->reason_code);
        $this->assertSame($this->approverId, (int) $audit->approver_id);
        $this->assertTrue((bool) $audit->high_risk);
    }
}
