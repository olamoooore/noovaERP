<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\PostingEngine;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\PeriodCloseService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue noovaerp/NoovaERP#68 — PeriodCloseService.reopenPeriod() must
 * actually reverse the prior closing entry. Without this, the
 * PostingEngine idempotency guard (keyed on period_close / periodId)
 * resolves the next close call to the SAME stale JE instead of
 * generating a fresh one against the period's current P&L balances.
 *
 * This test exercises the full path:
 *   1. post a closing JE (manually — for test isolation)
 *   2. reopen the period via PeriodCloseService::reopenPeriod()
 *   3. assert the closing JE is no longer active (reversed)
 *   4. assert a new active closing JE is generated on a subsequent close
 */
class Issue68ReopenPeriodReversesClosingTest extends TenantTestCase
{
    private string $tenantId;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);

        $this->user = User::factory()->create();
        $this->seed(RolePermissionSeeder::class);
        $this->user->assignRole('Admin');
        Auth::login($this->user);

        FiscalPeriod::create([
            'name' => 'Issue68 Period',
            'code' => 'P68-' . Str::random(6),
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'period_type' => 'yearly',
            'fiscal_year' => 2026,
            'status' => 'open',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_reopen_reverses_closing_je_and_subsequent_close_creates_a_new_one(): void
    {
        $period = FiscalPeriod::where('code', 'like', 'P68-%')->orderByDesc('created_at')->first();

        // Step 0: post real revenue/expense JEs through the engine so that
        // aggregatePeriodPLBalances() has something to find on re-close.
        // (The closing JE itself is period_close — only the business JEs
        // produce revenue/expense balances that survive the closing-entry
        // reversal.)
        $revenueAcct = $this->createTestAccount('revenue');
        $expenseAcct = $this->createTestAccount('expense');
        $cashAcct = $this->createTestAccount('asset');
        $this->postTestRevenueJe($revenueAcct, $cashAcct, 20000.00);
        $this->postTestExpenseJe($expenseAcct, $cashAcct, 7654.33);

        // Step 1: close the period via the service — this aggregates
        // revenue/expense balances and posts a period_close JE through
        // the engine (unlike the model's bare close() which just flips
        // status).
        $service = app(PeriodCloseService::class);
        $service->closePeriod($period, (string) $this->user->id);
        $period->refresh();

        $activeBefore = (int) DB::table('journal_entries')
            ->where('type', 'period_close')
            ->where('is_active_version', true)
            ->where('period_id', $period->id)
            ->count();
        $this->assertSame(1, $activeBefore, 'precondition: one active period_close JE for this period');

        // Capture the closing JE id for later comparison.
        $reId = (int) DB::table('journal_entries')
            ->where('type', 'period_close')
            ->where('is_active_version', true)
            ->where('period_id', $period->id)
            ->value('id');

        // Step 2: reopen — reverses the closing entry via
        // PostingEngine.reverse() (issue #68 fix). The underlying
        // revenue/expense JEs remain active (only the period_close is
        // reversed).
        $reopenResult = $service->reopenPeriod($period, (string) $this->user->id, 'Issue68 test reopen');
        $this->assertSame('open', $reopenResult['status']);
        $this->assertGreaterThanOrEqual(1, $reopenResult['removed_entries']);

        // Step 3: closing JE is no longer active (reversed by the engine).
        $original = DB::table('journal_entries')->where('id', $reId)->first();
        $this->assertFalse((bool) $original->is_active_version, 'original closing JE is_active_version=false after reopen');
        $this->assertSame('reversed', $original->lifecycle_status, 'original closing JE marked as reversed');

        // The period_close link is gone — idempotency guard won't reuse.
        $linkExists = DB::table('journal_entry_links')
            ->where('source_document_type', 'period_close')
            ->where('source_document_id', $period->id)
            ->exists();
        $this->assertFalse($linkExists, 'period_close link deleted — next close will generate a fresh JE');

        // The revenue/expense JEs are still active (reversal only affected
        // the period_close entry).
        $activeRevenueCount = (int) DB::table('journal_entries as je')
            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->where('je.is_active_version', true)
            ->where('jel.account_id', $revenueAcct)
            ->count('je.id');
        $this->assertGreaterThanOrEqual(1, $activeRevenueCount, 'revenue JE still active after closing-entry reversal');

        // Step 4: a subsequent close must create a NEW active closing JE
        // — the idempotency guard would have returned the stale
        // (reversed) one without the #68 fix.
        $period->refresh();
        $service->closePeriod($period, (string) $this->user->id);

        $activeFinal = (int) DB::table('journal_entries')
            ->where('type', 'period_close')
            ->where('is_active_version', true)
            ->where('period_id', $period->id)
            ->count();
        $this->assertSame(1, $activeFinal, 're-close generated a fresh active period_close JE');

        $freshId = (int) DB::table('journal_entries')
            ->where('type', 'period_close')
            ->where('is_active_version', true)
            ->where('period_id', $period->id)
            ->value('id');
        $this->assertNotSame($reId, $freshId, 'fresh closing JE id differs from the original (stale) one');
    }

    private function createTestAccount(string $type): int
    {
        return (int) DB::table('chart_of_accounts')->insertGetId([
            'code' => 'P68-' . strtoupper($type[0]) . '-' . Str::random(4),
            'name' => "Issue68 {$type} " . Str::random(4),
            'type' => $type,
            'account_type' => $type,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function postTestRevenueJe(int $revenueAcct, int $cashAcct, float $amount): void
    {
        $result = app(PostingEngine::class)->post(new PostingRequest(
            documentType: 'sales_invoice',
            documentId: 0,
            postingDate: '2026-06-15',
            description: 'Issue68 test revenue',
            lines: [
                new PostingRequestLine(accountMappingKey: 'revenue', accountId: $revenueAcct, debit: 0, credit: $amount, description: 'Revenue'),
                new PostingRequestLine(accountMappingKey: 'asset', accountId: $cashAcct, debit: $amount, credit: 0, description: 'Cash'),
            ],
            reference: 'P68-REV-' . Str::random(6),
            userId: (int) $this->user->id,
        ));
        $this->assertTrue($result->success, 'Revenue JE posted: ' . ($result->error ?? ''));
    }

    private function postTestExpenseJe(int $expenseAcct, int $cashAcct, float $amount): void
    {
        $result = app(PostingEngine::class)->post(new PostingRequest(
            documentType: 'purchase_invoice',
            documentId: 0,
            postingDate: '2026-07-20',
            description: 'Issue68 test expense',
            lines: [
                new PostingRequestLine(accountMappingKey: 'expense', accountId: $expenseAcct, debit: $amount, credit: 0, description: 'Expense'),
                new PostingRequestLine(accountMappingKey: 'asset', accountId: $cashAcct, debit: 0, credit: $amount, description: 'Cash'),
            ],
            reference: 'P68-EXP-' . Str::random(6),
            userId: (int) $this->user->id,
        ));
        $this->assertTrue($result->success, 'Expense JE posted: ' . ($result->error ?? ''));
    }
}
