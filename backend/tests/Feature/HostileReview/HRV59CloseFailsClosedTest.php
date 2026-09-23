<?php

declare(strict_types=1);

namespace Tests\Feature\HostileReview;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingResult;
use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Services\PeriodCloseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Issue59ClosingEntryViaPostingEngineTest;

/**
 * Hostile review repro (#59 HR59-01): with non-zero P&L, a failed closing-entry
 * posting must abort the close and leave the period OPEN.
 */
class HRV59CloseFailsClosedTest extends Issue59ClosingEntryViaPostingEngineTest
{
    public function test_period_does_not_close_when_closing_entry_posting_fails(): void
    {
        $period = \Closure::bind(fn () => $this->period, $this, Issue59ClosingEntryViaPostingEngineTest::class)();
        $user = \Closure::bind(fn () => $this->user, $this, Issue59ClosingEntryViaPostingEngineTest::class)();

        $revenue = ChartOfAccount::create(['code' => '49'.Str::random(4), 'name' => 'HRV59 revenue', 'type' => 'revenue', 'account_type' => 'revenue', 'is_active' => true]);
        $cash = ChartOfAccount::create(['code' => '19'.Str::random(4), 'name' => 'HRV59 cash', 'type' => 'asset', 'account_type' => 'asset', 'is_active' => true]);

        // Non-zero P&L inside the period (posted, active).
        $jeId = DB::table('journal_entries')->insertGetId([
            'entry_number' => 'HRV59-'.Str::random(5), 'entry_date' => '2026-05-15', 'description' => 'HRV59 sale',
            'status' => 'posted', 'is_active_version' => true, 'created_by' => $user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('journal_entry_lines')->insert([
            ['journal_entry_id' => $jeId, 'account_id' => $cash->id, 'debit' => 1000, 'credit' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['journal_entry_id' => $jeId, 'account_id' => $revenue->id, 'debit' => 0, 'credit' => 1000, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // The authoritative engine refuses the closing entry (e.g. a gate BLOCK).
        $engine = \Mockery::mock(PostingEngineInterface::class);
        $engine->shouldReceive('post')->andReturn(PostingResult::failure('GATE_BLOCKED: simulated'));
        $this->app->instance(PostingEngineInterface::class, $engine);
        $this->app->forgetInstance(PeriodCloseService::class);

        $threw = null;
        try {
            app(PeriodCloseService::class)->closePeriod($period, (string) $user->id);
        } catch (\Throwable $e) {
            $threw = $e->getMessage();
        }

        $status = FiscalPeriod::whereKey($period->id)->value('status');
        fwrite(STDERR, sprintf("\n[HRV59] closePeriod threw=%s period.status=%s closing_JEs=%d\n",
            $threw ?? 'NO', $status,
            DB::table('journal_entry_links')->where('source_document_type', 'period_close')->where('source_document_id', $period->id)->count()));

        $this->assertSame('open', $status, 'period must stay OPEN when the required closing entry was not posted');
    }
}
