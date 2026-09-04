<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\JournalEntryLink;
use App\Models\User;
use App\Services\PeriodCloseService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TenantTestCase;

/**
 * Issue noovaerp/NoovaERP#59 — PeriodCloseService must post closing
 * entries through PostingEngine, not raw query-builder inserts.
 */
class Issue59ClosingEntryViaPostingEngineTest extends TenantTestCase
{
    private string $tenantUuid;

    private User $user;

    private FiscalPeriod $period;

    private int $reAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = (string) Str::uuid();
        $this->bindTenantContext($this->tenantUuid);

        $this->user = User::factory()->create();
        $this->seed(RolePermissionSeeder::class);
        $this->user->assignRole('Admin');
        Auth::login($this->user);

        $this->period = FiscalPeriod::create([
            'name' => 'Closing probe period',
            'code' => 'CP-'.Str::random(6),
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'period_type' => 'monthly',
            'fiscal_year' => 2026,
            'status' => 'open',
            'created_by' => $this->user->id,
        ]);

        $mk = fn (string $code, string $type) => ChartOfAccount::create([
            'code' => $code, 'name' => $code, 'type' => $type, 'account_type' => $type, 'is_active' => true,
        ])->id;

        $this->reAccountId = $mk('3190-'.Str::random(4), 'equity');
    }

    private function invokePostClosingEntry(array $plBalances): ?array
    {
        $service = app(PeriodCloseService::class);
        $method = new ReflectionMethod($service, 'postClosingEntry');
        $method->setAccessible(true);

        return $method->invoke($service, $this->period, $this->reAccountId, $plBalances);
    }

    public function test_closing_entry_is_created_through_posting_engine(): void
    {
        $revenue = ChartOfAccount::create(['code' => '41'.Str::random(4), 'name' => 'Closing revenue', 'type' => 'revenue', 'account_type' => 'revenue', 'is_active' => true]);
        $expense = ChartOfAccount::create(['code' => '51'.Str::random(4), 'name' => 'Closing expense', 'type' => 'expense', 'account_type' => 'expense', 'is_active' => true]);

        $result = $this->invokePostClosingEntry([
            $revenue->id => 500.0,   // net revenue (credit-positive)
            $expense->id => -300.0,  // net expense (debit-positive shown negative)
        ]);

        $this->assertNotNull($result, 'postClosingEntry returned null');

        // Engine fingerprint #1: JournalEntryLink (period_close -> JE) —
        // raw inserts never created links.
        $link = JournalEntryLink::where('source_document_type', 'period_close')
            ->where('source_document_id', $this->period->id)
            ->where('relation_type', 'post')
            ->first();
        $this->assertNotNull($link, 'closing entry has no JournalEntryLink — not routed through PostingEngine');
        $this->assertSame($result['journal_entry_id'], $link->journal_entry_id);

        $entry = JournalEntry::with('lines')->find($result['journal_entry_id']);
        $this->assertSame('posted', $entry->status);
        $this->assertSame('period_close', $entry->type);
        $this->assertTrue((bool) $entry->is_period_close_entry);
        $this->assertEquals($this->period->id, (int) $entry->period_id);

        // Engine fingerprint #2: audit log + state transition rows exist.
        $this->assertDatabaseHas('posting_audit_logs', [
            'journal_entry_id' => $entry->id,
            'document_type' => 'period_close',
            'action' => 'post',
        ]);
        $this->assertDatabaseHas('state_transitions', [
            'aggregate_type' => 'JournalEntry',
            'aggregate_id' => (string) $entry->id,
        ]);

        // Content parity with the pre-fix shape: revenue debited, expense
        // credited, net income credited to retained earnings; balanced.
        $byAccount = collect($entry->lines)->keyBy('account_id');
        $this->assertEqualsWithDelta(500.0, (float) $byAccount[$revenue->id]->debit, 0.001);
        $this->assertEqualsWithDelta(300.0, (float) $byAccount[$expense->id]->credit, 0.001);
        $this->assertEqualsWithDelta(200.0, (float) $byAccount[$this->reAccountId]->credit, 0.001);
        $this->assertEqualsWithDelta(
            (float) $entry->lines->sum('debit'),
            (float) $entry->lines->sum('credit'),
            0.001
        );
    }

    public function test_repeat_close_attempt_is_idempotent(): void
    {
        $revenue = ChartOfAccount::create(['code' => '42'.Str::random(4), 'name' => 'Rev', 'type' => 'revenue', 'account_type' => 'revenue', 'is_active' => true]);

        $first = $this->invokePostClosingEntry([$revenue->id => 250.0]);
        $second = $this->invokePostClosingEntry([$revenue->id => 250.0]);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first['journal_entry_id'], $second['journal_entry_id']);
        $this->assertSame(1, JournalEntry::where('type', 'period_close')->where('period_id', $this->period->id)->count());
    }
}
