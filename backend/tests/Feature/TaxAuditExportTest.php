<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

/**
 * Issue #861 (RP7) — IRAS may ask a GST-registered business for its
 * transaction data as an audit file (company info, purchase, supply and GL
 * listings), and RMCD expects a structured SST audit listing of taxable
 * supplies by tax code with source documents and exemption references.
 *
 * Both exports must be scoped to one legal entity and one period and must use
 * the shared #860 lifecycle scope (originals kept — including reversed —
 * voided/superseded excluded) so the audit file can never disagree with the
 * tax trail and the return.
 *
 * Real HTTP through the router; postings go through the real PostingEngine.
 */
class TaxAuditExportTest extends TenantTestCase
{
    private User $user;

    private int $cashId;

    private string $cashCode;

    private int $revenueId;

    private string $revenueCode;

    private int $gstId;

    private int $inputTaxId;

    private int $expenseId;

    private int $payableId;

    private int $sgTaxId;

    private int $sgInputTaxId;

    private int $myServiceTaxId;

    private int $entityA;

    private int $entityB;

    private int $entityMy;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);

        $this->user = User::factory()->create(['email' => Str::lower(Str::random(3)).'-'.uniqid().'@rp7.test']);
        $this->user->assignRole('Admin');
        DB::table('user_company_access')->insert([
            'user_id' => $this->user->id,
            'tenant_id' => $tenant->id,
            'role' => 'admin',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->user);

        $cash = ChartOfAccount::factory()->create(['code' => 'RP7-CASH-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true]);
        $revenue = ChartOfAccount::factory()->create(['code' => 'RP7-REV-'.Str::upper(Str::random(4)), 'type' => 'revenue', 'is_active' => true]);
        $gst = ChartOfAccount::factory()->create(['code' => 'RP7-GST-'.Str::upper(Str::random(4)), 'type' => 'liability', 'is_active' => true]);
        $inputTax = ChartOfAccount::factory()->create(['code' => 'RP7-IN-'.Str::upper(Str::random(4)), 'type' => 'asset', 'is_active' => true]);
        $expense = ChartOfAccount::factory()->create(['code' => 'RP7-EXP-'.Str::upper(Str::random(4)), 'type' => 'expense', 'is_active' => true]);
        $payable = ChartOfAccount::factory()->create(['code' => 'RP7-AP-'.Str::upper(Str::random(4)), 'type' => 'liability', 'is_active' => true]);

        $this->cashId = $cash->id;
        $this->cashCode = (string) $cash->code;
        $this->revenueId = $revenue->id;
        $this->revenueCode = (string) $revenue->code;
        $this->gstId = $gst->id;
        $this->inputTaxId = $inputTax->id;
        $this->expenseId = $expense->id;
        $this->payableId = $payable->id;

        foreach ([
            'cash' => $cash->id,
            'sales_revenue' => $revenue->id,
            'sales_tax_payable' => $gst->id,
            'input_tax' => $inputTax->id,
            'general_expense' => $expense->id,
            'accounts_payable' => $payable->id,
        ] as $key => $accountId) {
            AccountMapping::updateOrCreate(['mapping_key' => $key], ['account_id' => $accountId, 'is_active' => true]);
        }

        $this->sgTaxId = (int) Tax::query()->create([
            'name' => 'RP7 SG GST 9', 'code' => 'SG-GST-9', 'type' => 'gst', 'regime' => 'GST',
            'jurisdiction' => 'SG', 'scope' => 'output', 'treatment' => 'standard_rated',
            'rate' => 9, 'is_active' => true,
        ])->id;

        $this->sgInputTaxId = (int) Tax::query()->create([
            'name' => 'RP7 SG Input GST 9', 'code' => 'SG-GST-IN', 'type' => 'gst', 'regime' => 'GST',
            'jurisdiction' => 'SG', 'scope' => 'input', 'treatment' => 'standard_rated',
            'rate' => 9, 'is_active' => true,
        ])->id;

        $this->myServiceTaxId = (int) Tax::query()->create([
            'name' => 'RP7 MY Service Tax 8', 'code' => 'MY-SST-8', 'type' => 'service', 'regime' => 'service_tax',
            'jurisdiction' => 'MY', 'scope' => 'output', 'treatment' => 'standard_rated',
            'rate' => 8, 'is_active' => true,
        ])->id;

        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'RP7 Group', 'code' => 'RP7G-'.Str::upper(Str::random(4)),
            'base_currency' => 'SGD', 'fiscal_year_start' => '01-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $entity = fn (string $name, string $country, array $extra = []) => DB::table('legal_entities')->insertGetId(array_merge([
            'group_id' => $groupId, 'name' => $name, 'code' => Str::upper(Str::random(6)),
            'country_code' => $country, 'base_currency' => $country === 'SG' ? 'SGD' : 'MYR',
            'fiscal_year_start' => '01-01', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ], $extra));

        $this->entityA = $entity('Alpha Pte Ltd', 'SG', [
            'registration_number' => '202012345A',
            'sst_registration_number' => 'M90312345X',
        ]);
        $this->entityB = $entity('Beta Pte Ltd', 'SG');
        $this->entityMy = $entity('Gamma Sdn Bhd', 'MY');
    }

    private function postTaxedSupply(int $entityId, float $taxable, float $rate, float $tax): int
    {
        $result = app(PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'invoice',
            documentId: null,
            postingDate: '2026-09-15',
            description: 'RP7 taxed supply',
            lines: [
                new PostingRequestLine(accountMappingKey: 'cash', accountId: $this->cashId, debit: $taxable + $tax, credit: 0.0),
                new PostingRequestLine(accountMappingKey: 'sales_revenue', accountId: $this->revenueId, debit: 0.0, credit: $taxable),
                new PostingRequestLine(accountMappingKey: 'sales_tax_payable', accountId: $this->gstId, debit: 0.0, credit: $tax, taxCode: 'SG-GST-9', taxId: $this->sgTaxId),
            ],
            userId: $this->user->id,
            legalEntityId: $entityId,
            taxDeterminationResult: [
                'id' => (string) Str::uuid(),
                'jurisdiction' => 'SG',
                'tax_amount' => $tax,
                'tax_lines' => [[
                    'tax_code' => 'SG-GST-9', 'taxable_base' => $taxable, 'tax_rate' => $rate,
                    'tax_amount' => $tax, 'treatment' => 'standard_rated',
                ]],
            ],
        ));

        $this->assertTrue($result->success, $result->error ?? '');

        return (int) $result->journalEntry->id;
    }

    private function postTaxedPurchase(int $entityId, float $taxable, float $tax): int
    {
        $result = app(PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'bill',
            documentId: null,
            postingDate: '2026-09-16',
            description: 'RP7 taxed purchase',
            lines: [
                new PostingRequestLine(accountMappingKey: 'general_expense', accountId: $this->expenseId, debit: $taxable, credit: 0.0),
                new PostingRequestLine(accountMappingKey: 'input_tax', accountId: $this->inputTaxId, debit: $tax, credit: 0.0, taxCode: 'SG-GST-IN', taxId: $this->sgInputTaxId),
                new PostingRequestLine(accountMappingKey: 'accounts_payable', accountId: $this->payableId, debit: 0.0, credit: $taxable + $tax),
            ],
            userId: $this->user->id,
            legalEntityId: $entityId,
            taxDeterminationResult: [
                'id' => (string) Str::uuid(),
                'jurisdiction' => 'SG',
                'tax_amount' => $tax,
                'tax_lines' => [[
                    'tax_code' => 'SG-GST-IN', 'taxable_base' => $taxable, 'tax_rate' => 9.0,
                    'tax_amount' => $tax, 'treatment' => 'standard_rated',
                ]],
            ],
        ));

        $this->assertTrue($result->success, $result->error ?? '');

        return (int) $result->journalEntry->id;
    }

    private function postMyServiceTaxSupply(int $entityId, float $taxable, float $tax): int
    {
        $result = app(PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'invoice',
            documentId: null,
            postingDate: '2026-09-15',
            description: 'RP7 MY service tax supply',
            lines: [
                new PostingRequestLine(accountMappingKey: 'cash', accountId: $this->cashId, debit: $taxable + $tax, credit: 0.0),
                new PostingRequestLine(accountMappingKey: 'sales_revenue', accountId: $this->revenueId, debit: 0.0, credit: $taxable),
                new PostingRequestLine(accountMappingKey: 'sales_tax_payable', accountId: $this->gstId, debit: 0.0, credit: $tax, taxCode: 'MY-SST-8', taxId: $this->myServiceTaxId),
            ],
            userId: $this->user->id,
            legalEntityId: $entityId,
            taxDeterminationResult: [
                'id' => (string) Str::uuid(),
                'jurisdiction' => 'MY',
                'tax_regime' => 'service_tax',
                'tax_amount' => $tax,
                'tax_lines' => [[
                    'tax_code' => 'MY-SST-8', 'taxable_base' => $taxable, 'tax_rate' => 8.0,
                    'tax_amount' => $tax, 'treatment' => 'standard_rated',
                ]],
            ],
        ));

        $this->assertTrue($result->success, $result->error ?? '');

        return (int) $result->journalEntry->id;
    }

    private function postExemptSupply(int $entityId, ?int $invoiceId, float $taxable): int
    {
        $result = app(PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'invoice',
            documentId: $invoiceId,
            postingDate: '2026-09-14',
            description: 'RP7 exempt supply',
            lines: [
                new PostingRequestLine(accountMappingKey: 'cash', accountId: $this->cashId, debit: $taxable, credit: 0.0),
                new PostingRequestLine(accountMappingKey: 'sales_revenue', accountId: $this->revenueId, debit: 0.0, credit: $taxable),
            ],
            userId: $this->user->id,
            legalEntityId: $entityId,
            taxDeterminationResult: [
                'id' => (string) Str::uuid(),
                'jurisdiction' => 'MY',
                'tax_regime' => 'service_tax',
                'tax_amount' => 0.0,
                'tax_lines' => [[
                    'tax_code' => 'MY-EXEMPT', 'taxable_base' => $taxable, 'tax_rate' => null,
                    'tax_amount' => 0.0, 'treatment' => 'exempt',
                ]],
            ],
        ));

        $this->assertTrue($result->success, $result->error ?? '');

        return (int) $result->journalEntry->id;
    }

    public function test_sg_iaf_export_lists_supplies_purchases_and_gl_for_one_entity(): void
    {
        $this->postTaxedSupply($this->entityA, 100.0, 9.0, 9.0);
        $this->postTaxedPurchase($this->entityA, 200.0, 18.0);

        // A second SG entity's activity must never leak into Alpha's audit file.
        $this->postTaxedSupply($this->entityB, 500.0, 9.0, 45.0);

        $response = $this->actingAs($this->user)->getJson(
            "/api/v1/accounting/tax-report/sg-iaf?period=2026-09&entity_id={$this->entityA}"
        );

        $response->assertOk()
            ->assertJsonPath('data.jurisdiction', 'SG')
            ->assertJsonPath('data.company.name', 'Alpha Pte Ltd')
            ->assertJsonPath('data.company.sst_registration_number', 'M90312345X')
            ->assertJsonPath('data.company.registration_number', '202012345A');

        $this->assertCount(1, $response->json('data.supply_listing'), 'Only Alpha supplies belong in Alpha IAF');
        $this->assertSame('SG-GST-9', $response->json('data.supply_listing.0.tax_code'));
        $this->assertSame(100.0, (float) $response->json('data.supply_listing.0.taxable_amount'));
        $this->assertSame(9.0, (float) $response->json('data.supply_listing.0.tax_amount'));
        $this->assertSame('invoice', $response->json('data.supply_listing.0.source_document_type'));
        $this->assertNotNull($response->json('data.supply_listing.0.journal_entry_id'));
        $this->assertNotNull($response->json('data.supply_listing.0.entry_number'));

        $this->assertCount(1, $response->json('data.purchase_listing'));
        $this->assertSame('SG-GST-IN', $response->json('data.purchase_listing.0.tax_code'));
        $this->assertSame(18.0, (float) $response->json('data.purchase_listing.0.tax_amount'));

        $gl = $response->json('data.gl_listing');
        $this->assertNotEmpty($gl, 'The IAF must include the entity GL listing');
        $glAccountCodes = array_column($gl, 'account_code');
        $this->assertContains($this->cashCode, $glAccountCodes);
        $this->assertContains($this->revenueCode, $glAccountCodes);

        $this->assertSame(100.0, (float) $response->json('data.totals.supply_taxable'));
        $this->assertSame(9.0, (float) $response->json('data.totals.supply_tax'));
        $this->assertSame(200.0, (float) $response->json('data.totals.purchase_taxable'));

        $this->assertNotSame('', (string) $response->json('data.spec_disclosure'));
        $this->assertStringContainsString('IRAS', (string) $response->json('data.spec_disclosure'));
    }

    public function test_sg_iaf_export_uses_the_shared_lifecycle_scope(): void
    {
        $voidedEntry = $this->postTaxedSupply($this->entityA, 100.0, 9.0, 9.0);
        $liveEntry = $this->postTaxedSupply($this->entityA, 300.0, 9.0, 27.0);

        DB::table('journal_entries')->where('id', $voidedEntry)->update(['lifecycle_status' => 'voided']);

        $response = $this->actingAs($this->user)->getJson(
            "/api/v1/accounting/tax-report/sg-iaf?period=2026-09&entity_id={$this->entityA}"
        );

        $response->assertOk();

        $journalIds = array_column($response->json('data.supply_listing'), 'journal_entry_id');
        $this->assertNotContains($voidedEntry, $journalIds, 'A voided entry must leave the IAF trail listing');
        $this->assertContains($liveEntry, $journalIds);
        $this->assertSame(300.0, (float) $response->json('data.totals.supply_taxable'));
        $this->assertSame(27.0, (float) $response->json('data.totals.supply_tax'));

        $glJournalIds = array_column($response->json('data.gl_listing'), 'journal_entry_id');
        $this->assertNotContains($voidedEntry, $glJournalIds, 'The GL listing must exclude the same voided entry as the trail');
        $this->assertSame([$liveEntry], array_values(array_unique($glJournalIds)));
    }

    public function test_my_sst_listing_groups_supplies_by_tax_code_with_document_links_and_exemption_references(): void
    {
        $invoice = Invoice::factory()->create([
            'invoice_date' => '2026-09-10',
            'legal_entity_id' => $this->entityMy,
            'tax_exemption_code' => 'EX-123',
            'tax_exemption_reason' => 'Schedule A exemption',
        ]);

        $this->postExemptSupply($this->entityMy, (int) $invoice->id, 50.0);
        $this->postMyServiceTaxSupply($this->entityMy, 100.0, 8.0);

        $url = "/api/v1/accounting/tax-report/my-sst-listing?period=2026-09&entity_id={$this->entityMy}";
        $response = $this->actingAs($this->user)->getJson($url);

        $response->assertOk()->assertJsonPath('data.jurisdiction', 'MY');

        $byCode = collect($response->json('data.by_tax_code'))->keyBy('tax_code');
        $this->assertTrue($byCode->has('MY-SST-8'), 'The SST listing must group taxable supplies by tax code');
        $this->assertSame(100.0, (float) $byCode['MY-SST-8']['taxable_amount']);
        $this->assertSame(8.0, (float) $byCode['MY-SST-8']['tax_amount']);
        $this->assertSame('service', $byCode['MY-SST-8']['tax_rule_type']);
        $this->assertCount(1, $byCode['MY-SST-8']['documents']);
        $this->assertNotNull($byCode['MY-SST-8']['documents'][0]['journal_entry_id']);
        $this->assertSame('invoice', $byCode['MY-SST-8']['documents'][0]['source_document_type']);

        $exemptions = $response->json('data.exemptions');
        $this->assertCount(1, $exemptions, 'Exempt supplies must be listed with their certificate reference');
        $this->assertSame('exempt', $exemptions[0]['category']);
        $this->assertSame('EX-123', $exemptions[0]['exemption_code']);
        $this->assertSame('Schedule A exemption', $exemptions[0]['exemption_reason']);
        $this->assertSame((int) $invoice->id, (int) $exemptions[0]['source_document_id']);
        $this->assertSame(0.0, (float) $exemptions[0]['tax_amount']);
        $this->assertNotSame('', (string) $response->json('data.spec_disclosure'));

        $csv = $this->actingAs($this->user)->get($url.'&format=csv');
        $csv->assertOk();
        $this->assertStringContainsString('text/csv', (string) $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('MY-SST-8', $csv->getContent());
        $this->assertStringContainsString('EX-123', $csv->getContent());
    }

    public function test_audit_exports_require_an_entity_and_its_country_must_match(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/tax-report/sg-iaf?period=2026-09')
            ->assertStatus(422);

        $this->actingAs($this->user)
            ->getJson('/api/v1/accounting/tax-report/my-sst-listing?period=2026-09')
            ->assertStatus(422);

        $this->actingAs($this->user)
            ->getJson("/api/v1/accounting/tax-report/sg-iaf?period=2026-09&entity_id={$this->entityMy}")
            ->assertStatus(422);

        $this->actingAs($this->user)
            ->getJson("/api/v1/accounting/tax-report/my-sst-listing?period=2026-09&entity_id={$this->entityA}")
            ->assertStatus(422);
    }
}
