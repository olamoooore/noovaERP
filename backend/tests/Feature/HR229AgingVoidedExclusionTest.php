<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\DocumentLifecycleService;
use App\Services\CorrectionRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #229 (regression from the #96 fix) — AgedReceivablesController's
 * company-wide aging matrix and per-customer aging queried invoices without
 * the notSuperseded() scope, so voided/reversed invoices still counted as
 * outstanding AR while the primary aging report excluded them.
 */
class HR229AgingVoidedExclusionTest extends TenantTestCase
{
    private string $tenantId;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        DB::table('public.tenants')->updateOrInsert(
            ['id' => $this->tenantId],
            ['name' => 'HR229 Tenant', 'email' => 'hr229-'.Str::random(6).'@example.com', 'created_at' => now(), 'updated_at' => now()]
        );

        $this->admin = User::factory()->create();
        DB::table('public.users')->updateOrInsert(
            ['email' => $this->admin->email],
            ['name' => $this->admin->name, 'password' => $this->admin->password, 'created_at' => now(), 'updated_at' => now()]
        );
        $centralId = DB::table('public.users')->where('email', $this->admin->email)->value('id');
        DB::table('user_company_access')->updateOrInsert(
            ['user_id' => $centralId, 'tenant_id' => $this->tenantId],
            ['role' => 'admin', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]
        );
        $this->admin->assignRole('Admin');

        $this->customer = Customer::factory()->create(['payment_terms' => 30, 'is_active' => true]);
    }

    private function postedInvoice(float $balance): Invoice
    {
        return Invoice::factory()->posted()->create([
            'customer_id' => $this->customer->id,
            'invoice_date' => now()->subDays(60)->toDateString(),
            'due_date' => now()->subDays(30)->toDateString(),
            'total' => $balance,
            'balance' => $balance,
            'paid_amount' => 0,
        ]);
    }

    private function void(Invoice $invoice): void
    {
        app(DocumentLifecycleService::class)->markVoided($invoice->fresh(), new CorrectionRequest(
            userId: $this->admin->id,
            reasonCode: 'data_entry_error',
            reasonText: 'HR229 test void',
        ));
    }

    // ─────────────────────────────────────────────────────────────────────

    public function test_company_wide_aging_excludes_voided_invoices(): void
    {
        $live = $this->postedInvoice(100.00);
        $voided = $this->postedInvoice(999.00);
        $this->void($voided);

        $response = $this->actingAs($this->admin)->getJson('/api/v1/accounting/reports/aging');
        $response->assertStatus(200, $response->getContent());

        $row = collect($response->json('data') ?? $response->json())
            ->firstWhere('customer_id', $this->customer->id);

        $this->assertNotNull($row, 'The customer must appear in the aging matrix');
        $this->assertSame(100.0, round((float) $row['total'], 2), 'Voided invoice balance must not be counted');
        // The live invoice is 30 days past due -> the 1_30 bucket holds it.
        $this->assertSame(100.0, round((float) ($row['1_30'] ?? 0), 2));
    }

    public function test_per_customer_aging_excludes_voided_invoices(): void
    {
        $live = $this->postedInvoice(100.00);
        $voided = $this->postedInvoice(999.00);
        $this->void($voided);

        $response = $this->actingAs($this->admin)->getJson("/api/v1/customers/{$this->customer->id}/aging");
        $response->assertStatus(200, $response->getContent());

        $details = collect($response->json('invoices') ?? []);
        $ids = $details->pluck('id')->all();

        $this->assertContains($live->id, $ids, 'The live invoice must be present');
        $this->assertNotContains($voided->id, $ids, 'The voided invoice must be excluded');

        $this->assertSame(100.0, round((float) $response->json('total_outstanding'), 2), 'Only the live balance may be aged');
    }
}
