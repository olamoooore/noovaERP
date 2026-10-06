<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * ADR-003B W6 step 2, hostile-review step 3: a read-only report of POSTED tax
 * recorded under a jurisdiction that is not the recording entity's own
 * (missing, unrecognised text such as "MALAYSIA", or another country's code),
 * by period, direction, document type and currency, with the periods whose
 * return already went to the authority marked. It must write nothing.
 */
class TaxTrailJurisdictionReportTest extends TenantTestCase
{
    private string $tenantId;

    private string $reportPath;

    private User $poster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContextWithPermissions((string) Str::uuid());
        $this->tenantId = $this->boundTestTenant->getTenantKey();

        DB::table('public.tenants')->insertOrIgnore([
            'id' => $this->tenantId,
            'name' => 'Tax Trail Report Tenant',
            'email' => $this->tenantId.'@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Setting::setValue('tax', ['jurisdiction' => 'MY']);
        $this->poster = User::factory()->create();

        $this->reportPath = tempnam(sys_get_temp_dir(), 'tax-trail-');
    }

    protected function tearDown(): void
    {
        @unlink($this->reportPath);
        parent::tearDown();
    }

    public function test_report_lists_tax_recorded_outside_home_with_filed_periods_and_writes_nothing(): void
    {
        $sgEntity = $this->entityRegisteredIn('SG');

        // Tenant home (MY), no entity on the journal entry.
        $this->trailRow(null, 'MY', '2026-03', 'output', 'pos_order', 100, 8);
        $this->trailRow(null, 'MALAYSIA', '2026-03', 'output', 'pos_order', 200, 16);
        $this->trailRow(null, 'MALAYSIA', '2026-03', 'output', 'pos_order', 50, 4);
        $this->trailRow(null, 'SG', '2026-04', 'input', 'bill', 300, 24);
        $this->trailRow(null, null, '2026-05', 'output', 'storefront_order', 10, 1);

        // An entity registered in SG: SG is home, MY is foreign for it.
        $this->trailRow($sgEntity, 'SG', '2026-03', 'output', 'invoice', 1000, 90);
        $this->trailRow($sgEntity, 'MY', '2026-03', 'input', 'bill', 400, 32);

        // Q1 went to the authority; Q2 is only prepared.
        $this->filing('MY', 'SST-02', '2026-Q1', 0, 'SUBMITTED');
        $this->filing('MY', 'SST-02', '2026-Q2', 0, 'PREPARED');
        $this->filing('SG', 'F5', '2026-Q1', $sgEntity, 'ACCEPTED');

        // May is closed without a filing row (a tenant-wide #278 lock); April
        // is locked only for the SG entity, which has no row there.
        $this->lock('2026-05', null);
        $this->lock('2026-04', $sgEntity);

        $before = $this->counts();

        $this->artisan('tax:trail-jurisdiction-report', ['--tenant' => $this->tenantId, '--json' => $this->reportPath])
            ->expectsOutputToContain('REPORT ONLY')
            ->assertExitCode(0);

        $this->assertSame($before, $this->counts(), 'The report must not write anything.');

        $report = json_decode((string) file_get_contents($this->reportPath), true)[0];
        $this->assertSame('MY', $report['tenant_home_jurisdiction']);

        $groups = collect($report['groups'])->keyBy(fn ($g) => ($g['legal_entity_id'] ?? '-').'|'.($g['recorded_jurisdiction'] ?? '-').'|'.$g['reporting_period']);
        $this->assertCount(4, $groups, 'Only the rows outside the recording entity\'s home are listed.');

        $malaysia = $groups['-|MALAYSIA|2026-03'];
        $this->assertSame('unrecognised_text', $malaysia['category']);
        $this->assertSame(2, $malaysia['rows']);
        $this->assertSame(250.0, (float) $malaysia['taxable_amount']);
        $this->assertSame(20.0, (float) $malaysia['tax_amount']);
        $this->assertTrue($malaysia['period_filed'], 'March is inside the submitted Q1 MY return.');

        $sgBill = $groups['-|SG|2026-04'];
        $this->assertSame('foreign_code', $sgBill['category']);
        $this->assertSame('input', $sgBill['direction']);
        $this->assertSame('bill', $sgBill['source_document_type']);
        $this->assertFalse($sgBill['period_filed'], 'A Q2 return that is only prepared is not filed.');
        $this->assertSame('PREPARED', $sgBill['filings'][0]['state']);
        $this->assertFalse($sgBill['period_locked'], 'An entity lock does not cover rows without that entity.');

        $this->assertSame('missing', $groups['-|-|2026-05']['category']);
        $this->assertFalse($groups['-|-|2026-05']['period_filed']);
        $this->assertTrue($groups['-|-|2026-05']['period_locked'], 'A period closed without a filing row is still locked.');
        $this->assertFalse($malaysia['period_locked']);
        $this->assertFalse($malaysia['amendment_required']);

        $entityMy = $groups[$sgEntity.'|MY|2026-03'];
        $this->assertSame(['SG'], $entityMy['home_jurisdictions']);
        $this->assertSame('foreign_code', $entityMy['category']);
        $this->assertTrue($entityMy['period_filed'], 'The entity\'s own SG Q1 return was accepted.');
        $this->assertSame('SG', $entityMy['filings'][0]['country']);

        $summary = $report['summary'];
        $this->assertSame(7, $summary['rows_checked']);
        $this->assertSame(5, $summary['rows_outside_home']);
        $this->assertSame(['2026-03', '2026-04', '2026-05'], $summary['periods_affected']);
        $this->assertSame(['2026-03'], $summary['filed_periods_affected']);
        $this->assertSame(['2026-05'], $summary['locked_periods_affected']);
        $this->assertEqualsCanonicalizing(['MALAYSIA', 'SG', 'MY', '(missing)'], $summary['recorded_jurisdictions']);
        $this->assertSame(21.0, (float) $summary['tax_outside_home']['output|MYR']);
        $this->assertSame(56.0, (float) $summary['tax_outside_home']['input|MYR']);
    }

    private function entityRegisteredIn(string $jurisdiction): int
    {
        $groupId = DB::table('entity_groups')->insertGetId([
            'name' => 'Trail Group',
            'code' => 'TG-'.Str::upper(Str::random(5)),
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $entityId = (int) DB::table('legal_entities')->insertGetId([
            'group_id' => $groupId,
            'name' => 'Trail Entity',
            'code' => 'TE-'.Str::upper(Str::random(5)),
            'country_code' => $jurisdiction,
            'base_currency' => 'MYR',
            'fiscal_year_start' => '01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tax_registrations')->insert([
            'legal_entity_id' => $entityId,
            'jurisdiction' => $jurisdiction,
            'regime' => 'GST',
            'registration_number' => 'REG-'.Str::random(6),
            'effective_from' => '2025-01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $entityId;
    }

    private function trailRow(?int $entityId, ?string $jurisdiction, string $period, string $direction, string $type, float $taxable, float $tax): void
    {
        $entry = JournalEntry::factory()->posted()->create(['legal_entity_id' => $entityId, 'posted_by' => $this->poster->id, 'created_by' => $this->poster->id]);

        DB::table('tax_transaction_details')->insert([
            'journal_entry_id' => $entry->id,
            'source_document_type' => $type,
            'source_document_id' => random_int(1, 1_000_000),
            'tax_code' => 'T',
            'taxable_amount' => $taxable,
            'tax_amount' => $tax,
            'jurisdiction' => $jurisdiction,
            'reporting_period' => $period,
            'direction' => $direction,
            'currency_code' => 'MYR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function filing(string $country, string $form, string $period, int $entityKey, string $state): void
    {
        DB::table('statutory_return_filings')->insert([
            'tenant_id' => $this->tenantId,
            'country' => $country,
            'form' => $form,
            'period' => $period,
            'entity_key' => $entityKey,
            'boxes' => json_encode([]),
            'filed_at' => now(),
            'state' => $state,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function lock(string $period, ?int $entityId): void
    {
        DB::table('tax_period_locks')->insert([
            'tenant_id' => 0,
            'period' => $period,
            'legal_entity_id' => $entityId,
            'closed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<int> */
    private function counts(): array
    {
        return [
            DB::table('tax_transaction_details')->count(),
            DB::table('journal_entries')->count(),
            DB::table('statutory_return_filings')->count(),
            DB::table('tax_registrations')->count(),
            DB::table('tax_period_locks')->count(),
        ];
    }
}
