<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #93 — statutory return generators (SG GST F5, MY SST-02) built from
 * the tax_transaction_details audit rows PostingEngine writes.
 */
class Issue93StatutoryReturnGeneratorTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create([
            'id' => '93939393-0303-1313-2323-333435363738',
        ]));
        $this->bindTenantContext($tenant->id);
        $this->assertTenantSchemaReady(['tax_transaction_details']);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function taxRow(array $overrides = []): void
    {
        // Hostile re-review (#93): tax details must belong to a real posted
        // journal entry — a fake journal_entry_id never exercises the live
        // post/reverse pipeline.
        $entry = JournalEntry::create([
            'entry_number' => 'JE-93-'.Str::upper(Str::random(6)),
            'entry_date' => now()->toDateString(),
            'status' => 'posted',
            'is_active_version' => true,
            'created_by' => $this->user->id,
        ]);

        DB::table('tax_transaction_details')->insert(array_merge([
            'journal_entry_id' => $entry->id,
            'source_document_type' => 'invoice',
            'source_document_id' => 1,
            'tax_code' => 'SR',
            'taxable_amount' => 1000,
            'tax_amount' => 90,
            'jurisdiction' => 'SG',
            'reporting_period' => '2026-09',
            'tax_rate' => 9,
            'tax_treatment' => 'standard',
            'tax_regime' => 'gst',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_singapore_gst_f5_boxes_are_aggregated_from_tax_details(): void
    {
        $this->taxRow(['source_document_type' => 'invoice', 'taxable_amount' => 10000, 'tax_amount' => 900, 'tax_rate' => 9]);
        $this->taxRow(['source_document_type' => 'invoice', 'tax_code' => 'ZR', 'taxable_amount' => 5000, 'tax_amount' => 0, 'tax_rate' => 0, 'tax_treatment' => 'zero_rated']);
        $this->taxRow(['source_document_type' => 'invoice', 'tax_code' => 'ES', 'taxable_amount' => 2000, 'tax_amount' => 0, 'tax_rate' => 0, 'tax_treatment' => 'exempt']);
        $this->taxRow(['source_document_type' => 'bill', 'taxable_amount' => 4000, 'tax_amount' => 360, 'tax_rate' => 9]);

        // A different period must not leak into the return.
        $this->taxRow(['reporting_period' => '2026-08', 'taxable_amount' => 99999, 'tax_amount' => 9999]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/accounting/statutory-returns/SG/2026-09');

        $response->assertOk();
        $boxes = $response->json('data.boxes');

        $this->assertSame('GST F5', $response->json('data.form'));
        $this->assertSame(10000.0, (float) $boxes['box_1_standard_rated_supplies']);
        $this->assertSame(5000.0, (float) $boxes['box_2_zero_rated_supplies']);
        $this->assertSame(2000.0, (float) $boxes['box_3_exempt_supplies']);
        $this->assertSame(4000.0, (float) $boxes['box_5_taxable_purchases']);
        $this->assertSame(900.0, (float) $boxes['box_6_output_tax_due']);
        $this->assertSame(360.0, (float) $boxes['box_7_input_tax_and_refunds']);
        $this->assertSame(540.0, (float) $boxes['box_8_net_gst_payable']);
    }

    public function test_credit_notes_reduce_the_singapore_return(): void
    {
        $this->taxRow(['source_document_type' => 'invoice', 'taxable_amount' => 10000, 'tax_amount' => 900, 'tax_rate' => 9]);
        $this->taxRow(['source_document_type' => 'credit_note', 'taxable_amount' => 2000, 'tax_amount' => 180, 'tax_rate' => 9]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/accounting/statutory-returns/SG/2026-09');

        $response->assertOk();
        $this->assertSame(8000.0, (float) $response->json('data.boxes.box_1_standard_rated_supplies'));
        $this->assertSame(720.0, (float) $response->json('data.boxes.box_6_output_tax_due'));
    }

    public function test_malaysia_sst_02_separates_sales_and_service_tax(): void
    {
        $this->taxRow(['jurisdiction' => 'MY', 'tax_regime' => 'sales_tax', 'tax_code' => 'ST5', 'taxable_amount' => 10000, 'tax_amount' => 500, 'tax_rate' => 5]);
        $this->taxRow(['jurisdiction' => 'MY', 'tax_regime' => 'service_tax', 'tax_code' => 'ST6', 'taxable_amount' => 2000, 'tax_amount' => 120, 'tax_rate' => 6]);

        $response = $this->actingAs($this->user)->getJson('/api/v1/accounting/statutory-returns/MY/2026-09');

        $response->assertOk();
        $this->assertSame('SST-02', $response->json('data.form'));
        $this->assertSame(10000.0, (float) $response->json('data.sales_tax.taxable_value'));
        $this->assertSame(500.0, (float) $response->json('data.sales_tax.tax_due'));
        $this->assertSame(2000.0, (float) $response->json('data.service_tax.taxable_value'));
        $this->assertSame(120.0, (float) $response->json('data.service_tax.tax_due'));
        $this->assertSame(620.0, (float) $response->json('data.total_tax_payable'));

        // Rate breakdown is preserved for the RMCD form.
        $rates = collect($response->json('data.sales_tax.by_rate'))->pluck('rate')->all();
        $this->assertSame(['5'], $rates);
    }

    public function test_f7_discloses_errors_against_a_filed_f5(): void
    {
        // File the F5 with one standard-rated invoice (output tax 900).
        $this->taxRow(['source_document_type' => 'invoice', 'taxable_amount' => 10000, 'tax_amount' => 900, 'tax_rate' => 9]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/accounting/statutory-returns/SG/2026-09/file')
            ->assertStatus(201);

        // A missed invoice is discovered afterwards (under-declared output tax).
        $this->taxRow(['source_document_type' => 'invoice', 'taxable_amount' => 5000, 'tax_amount' => 450, 'tax_rate' => 9]);

        $f7 = $this->actingAs($this->user)->getJson('/api/v1/accounting/statutory-returns/SG/2026-09/f7');

        $f7->assertOk();
        $this->assertSame('GST F7', $f7->json('data.form'));
        $this->assertSame(450.0, (float) $f7->json('data.error_amounts.box_6_output_tax_due'));
        $this->assertSame('under_declared', $f7->json('data.classification.output_tax'));
        $this->assertSame(450.0, (float) $f7->json('data.net_gst_error'));
    }

    public function test_f7_requires_a_filed_f5(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/statutory-returns/SG/2026-09/f7')
            ->assertStatus(422);
    }

    public function test_the_capability_index_lists_f7_as_available(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/accounting/statutory-returns');

        $response->assertOk();
        $forms = collect($response->json('data'))->where('country', 'SG')->pluck('form')->all();
        $this->assertContains('GST F7', $forms);
        $this->assertSame([], $response->json('planned'));
    }

    public function test_unsupported_country_and_bad_period_are_rejected(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/statutory-returns/US/2026-09')
            ->assertStatus(404);

        $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/statutory-returns/SG/2026-9')
            ->assertStatus(422);
    }

    public function test_supported_returns_are_listed(): void
    {
        $response = $this->actingAs($this->user)->getJson('/api/v1/accounting/statutory-returns');

        $response->assertOk();
        $this->assertCount(3, $response->json('data')); // F5, SST-02, F7
    }
}
