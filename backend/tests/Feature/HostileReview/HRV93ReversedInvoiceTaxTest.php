<?php

declare(strict_types=1);

namespace Tests\Feature\HostileReview;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\ReversalRequest;
use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Issue93StatutoryReturnGeneratorTest;

/**
 * Hostile review repro (#93): once a taxable invoice's journal entry is
 * reversed through the real PostingEngine, its output tax must leave the F5.
 */
class HRV93ReversedInvoiceTaxTest extends Issue93StatutoryReturnGeneratorTest
{
    public function test_reversed_invoice_output_tax_leaves_the_gst_return(): void
    {
        $user = \Closure::bind(fn () => $this->user, $this, Issue93StatutoryReturnGeneratorTest::class)();
        $this->actingAs($user);
        $period = now()->format('Y-m');

        FiscalPeriod::firstOrCreate(['code' => $period], [
            'name' => $period, 'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(), 'period_type' => 'monthly',
            'fiscal_year' => (int) now()->format('Y'), 'is_locked' => false, 'status' => 'open', 'created_by' => $user->id,
        ]);

        $ar = ChartOfAccount::create(['code' => '11'.Str::random(4), 'name' => 'AR', 'type' => 'asset', 'account_type' => 'asset', 'is_active' => true]);
        $rev = ChartOfAccount::create(['code' => '41'.Str::random(4), 'name' => 'Sales', 'type' => 'revenue', 'account_type' => 'revenue', 'is_active' => true]);
        $gst = ChartOfAccount::create(['code' => '21'.Str::random(4), 'name' => 'GST out', 'type' => 'liability', 'account_type' => 'liability', 'is_active' => true]);

        $jeId = DB::table('journal_entries')->insertGetId([
            'entry_number' => 'HRV93-'.Str::random(5), 'entry_date' => now()->toDateString(), 'description' => 'invoice',
            'status' => 'posted', 'is_active_version' => true, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('journal_entry_lines')->insert([
            ['journal_entry_id' => $jeId, 'account_id' => $ar->id, 'debit' => 1090, 'credit' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['journal_entry_id' => $jeId, 'account_id' => $rev->id, 'debit' => 0, 'credit' => 1000, 'created_at' => now(), 'updated_at' => now()],
            ['journal_entry_id' => $jeId, 'account_id' => $gst->id, 'debit' => 0, 'credit' => 90, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('tax_transaction_details')->insert([
            'journal_entry_id' => $jeId, 'source_document_type' => 'invoice', 'source_document_id' => 1,
            'tax_code' => 'SR', 'taxable_amount' => 1000, 'tax_amount' => 90, 'jurisdiction' => 'SG',
            'reporting_period' => $period, 'tax_rate' => 9, 'tax_treatment' => 'standard', 'tax_regime' => 'gst',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $before = $this->getJson("/api/v1/accounting/statutory-returns/SG/{$period}")->json('data.boxes.box_6_output_tax_due')
            ?? $this->getJson("/api/v1/accounting/statutory-returns/SG/{$period}")->json('boxes.box_6_output_tax_due');

        $result = app(PostingEngineInterface::class)->reverse(new ReversalRequest(
            journalEntryId: $jeId, reversalDate: now()->toDateString(), reason: 'invoice voided', actorUserId: $user->id,
        ));

        $res = $this->getJson("/api/v1/accounting/statutory-returns/SG/{$period}");
        $after = $res->json('data.boxes.box_6_output_tax_due') ?? $res->json('boxes.box_6_output_tax_due');

        fwrite(STDERR, sprintf("\n[HRV93] reverse.success=%s error=%s original.active=%s tax_rows_for_reversal=%d box6_before=%s box6_after=%s\n",
            var_export($result->success, true), $result->error ?? '-',
            var_export((bool) DB::table('journal_entries')->where('id', $jeId)->value('is_active_version'), true),
            DB::table('tax_transaction_details')->where('journal_entry_id', '!=', $jeId)->count(),
            var_export($before, true), var_export($after, true)));

        $this->assertTrue($result->success, 'reversal must succeed for the scenario to be meaningful');
        $this->assertEquals(0.0, (float) $after, 'a reversed invoice must not keep its output tax in the GST F5');
    }
}
