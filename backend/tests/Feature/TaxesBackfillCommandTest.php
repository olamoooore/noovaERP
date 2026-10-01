<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\TaxesBackfill;
use App\Models\JournalEntry;
use App\Models\PostingAuditLog;
use App\Models\Tax;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * #267: backfill tool — dry-run reports, identity-only execute with token,
 * append-only audit, idempotent, anomaly scan (report-only).
 */
class TaxesBackfillCommandTest extends TenantTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::withoutEvents(fn () => Tenant::factory()->create(['id' => (string) Str::uuid()]));
        $this->bindTenantContext($tenant->id);
    }

    private function seedLegacyRow(float $taxAmount = 9.0, float $taxRate = 9.0, string $taxCode = 'LEG-GST-9'): int
    {
        $rule = Tax::create([
            'name' => 'Legacy rule', 'code' => $taxCode, 'type' => 'gst', 'regime' => 'GST',
            'jurisdiction' => 'SG', 'scope' => 'both', 'treatment' => 'standard_rated',
            'rate' => $taxRate, 'is_active' => true,
        ]);

        $entry = JournalEntry::factory()->create(['status' => 'posted', 'entry_date' => '2026-04-15']);

        $accountId = (int) (\App\Models\ChartOfAccount::query()->value('id')
            ?: \App\Models\ChartOfAccount::create([
                'code' => 'BF-'.Str::upper(Str::random(4)), 'name' => 'Backfill account',
                'type' => 'liability', 'account_type' => 'liability', 'is_active' => true,
            ])->id);

        $lineId = (int) DB::table('journal_entry_lines')->insertGetId([
            'journal_entry_id' => $entry->id,
            'account_id' => $accountId,
            'description' => 'Legacy tax line (un-tagged)',
            'debit' => 0,
            'credit' => $taxAmount,
            'tax_code' => null,
            'tax_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('tax_transaction_details')->insert([
            'journal_entry_id' => $entry->id,
            'source_document_type' => 'invoice',
            'source_document_id' => 1,
            'tax_code' => $taxCode,
            'tax_rule_id' => $rule->id,
            'taxable_amount' => 100,
            'tax_amount' => $taxAmount,
            'jurisdiction' => 'SG',
            'reporting_period' => '2026-04',
            'tax_rate' => $taxRate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $lineId;
    }

    public function test_dry_run_reports_without_writing_and_execute_retags_identity_only(): void
    {
        $lineId = $this->seedLegacyRow();
        $command = app(TaxesBackfill::class);

        $dry = $command->auditCurrentSchema(false);
        $this->assertSame(1, $dry['identity_candidates']);
        $this->assertSame(0, $dry['retagged']);
        $this->assertNull(DB::table('journal_entry_lines')->where('id', $lineId)->value('tax_code'), 'Dry-run must not write.');

        $executed = $command->auditCurrentSchema(true);
        $this->assertSame(1, $executed['retagged']);

        $row = DB::table('journal_entry_lines')->where('id', $lineId)->first();
        $this->assertSame('LEG-GST-9', $row->tax_code);
        $this->assertNotNull($row->tax_id);

        $this->assertTrue(
            PostingAuditLog::where('action', 'tax_backfill_identity')->where('journal_entry_id', $row->journal_entry_id)->exists(),
            'Identity re-tagging must append an audit row.'
        );

        // Idempotent: nothing left to re-tag.
        $again = $command->auditCurrentSchema(true);
        $this->assertSame(0, $again['identity_candidates']);
        $this->assertSame(0, $again['retagged']);
    }

    public function test_execute_requires_the_confirmation_token(): void
    {
        $command = app(TaxesBackfill::class);

        $this->assertFalse($command->tokenIsValid(null));
        $this->assertFalse($command->tokenIsValid('WRONG'));
        $this->assertTrue($command->tokenIsValid(TaxesBackfill::TOKEN));
    }

    public function test_anomaly_scan_flags_percentage_basis_confusion_without_touching_rows(): void
    {
        // Legacy decimal-rate row: 0.04 stored where 4% was meant.
        $this->seedLegacyRow(taxAmount: 4.0, taxRate: 0.04, taxCode: 'LEG-BASIS');

        $before = DB::table('tax_transaction_details')->count();
        $report = app(TaxesBackfill::class)->auditCurrentSchema(false);
        $after = DB::table('tax_transaction_details')->count();

        $this->assertGreaterThanOrEqual(1, $report['anomalies'], 'Basis-confused rows must be flagged.');
        $this->assertSame($before, $after, 'Anomaly scanning is report-only — never deletes or rewrites.');
    }
}