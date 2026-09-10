<?php

/**
 * HR68-01 — PeriodCloseService::reopenPeriod() fail-closed atomicity.
 *
 * Issue #68 re-review (HR68-01) required the reversal/correction step to
 * FAIL CLOSED: on ACC-002 reversal failure, the outer reopenPeriod()
 * transaction must roll back so that no mixed authoritative state survives
 * (original closing JE + journal_entry_link + period state all unchanged).
 * The prior code absorbed reversal failure with `Log::error` + `continue`
 * and deleted the period_close link BEFORE invoking reversal — both of
 * which left mixed authoritative state on failure.
 *
 * This test exercises the real reopenPeriod path with PostingEngine::reverse
 * stubbed to return failure and proves:
 *  - reopenPeriod throws (no silent pass-through);
 *  - original closing JE remains is_active_version=true and not reversed;
 *  - journal_entry_links row for period_close remains present (idempotency
 *    guard reference coherent);
 *  - period status remains `closed` (not reopened);
 *  - no reversal journal entry was created;
 *  - the outer DB::transaction rolled back: the whole reopen attempt left
 *    zero authoritative delta.
 */

namespace Tests\Feature;

use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\PostingResult;
use App\Accounting\PostingEngine;
use App\Models\FiscalPeriod;
use App\Models\User;
use App\Services\PeriodCloseService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

class HR68ReopenPeriodFailClosedTest extends TenantTestCase
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
    }

    private function makeOpenPeriod(): FiscalPeriod
    {
        return FiscalPeriod::create([
            'name' => 'HR68 fail-closed period',
            'code' => 'HR68-'.Str::random(6),
            'period_start' => '2026-01-01',
            'period_end' => '2026-12-31',
            'period_type' => 'yearly',
            'fiscal_year' => 2026,
            'status' => 'open',
            'created_by' => $this->user->id,
        ]);
    }

    private function createTestAccount(string $type): int
    {
        return (int) DB::table('chart_of_accounts')->insertGetId([
            'code' => 'HR68-'.strtoupper($type[0]).'-'.Str::random(4),
            'name' => "HR68 {$type} ".Str::random(4),
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
            description: 'HR68 test revenue',
            lines: [
                new PostingRequestLine(accountMappingKey: 'revenue', accountId: $revenueAcct, debit: 0, credit: $amount, description: 'Revenue'),
                new PostingRequestLine(accountMappingKey: 'asset', accountId: $cashAcct, debit: $amount, credit: 0, description: 'Cash'),
            ],
            reference: 'HR68-REV-'.Str::random(6),
            userId: (int) $this->user->id,
        ));
        $this->assertTrue($result->success, 'Revenue JE posted: '.($result->error ?? ''));
    }

    private function postTestExpenseJe(int $expenseAcct, int $cashAcct, float $amount): void
    {
        $result = app(PostingEngine::class)->post(new PostingRequest(
            documentType: 'purchase_invoice',
            documentId: 0,
            postingDate: '2026-07-20',
            description: 'HR68 test expense',
            lines: [
                new PostingRequestLine(accountMappingKey: 'expense', accountId: $expenseAcct, debit: $amount, credit: 0, description: 'Expense'),
                new PostingRequestLine(accountMappingKey: 'asset', accountId: $cashAcct, debit: 0, credit: $amount, description: 'Cash'),
            ],
            reference: 'HR68-EXP-'.Str::random(6),
            userId: (int) $this->user->id,
        ));
        $this->assertTrue($result->success, 'Expense JE posted: '.($result->error ?? ''));
    }

    /** @test */
    public function hr68_01_failed_reversal_aborts_reopen_with_zero_authoritative_delta(): void
    {
        $service = app(\App\Services\PeriodCloseService::class);

        // Step 1 — close the period via the service so a real period_close JE
        // and journal_entry_link are created (proves the fixture is valid
        // before we induce the reversal failure).
        $period = $this->makeOpenPeriod();

        // Post real revenue/expense JEs first so aggregatePeriodPLBalances()
        // produces a closing JE during closePeriod (otherwise no period_close
        // JE exists and the fail-closed path is not exercised).
        $revenueAcct = $this->createTestAccount('revenue');
        $expenseAcct = $this->createTestAccount('expense');
        $cashAcct = $this->createTestAccount('asset');
        $this->postTestRevenueJe($revenueAcct, $cashAcct, 20000.00);
        $this->postTestExpenseJe($expenseAcct, $cashAcct, 7654.33);

        $service->closePeriod($period, (string) $this->user->id);

        $activeBefore = (int) DB::table('journal_entries')
            ->where('type', 'period_close')
            ->where('is_active_version', true)
            ->where('period_id', $period->id)
            ->count();
        $this->assertSame(1, $activeBefore, 'precondition: one active period_close JE for this period');

        $linkBefore = DB::table('journal_entry_links')
            ->where('source_document_type', 'period_close')
            ->where('source_document_id', $period->id)
            ->count();
        $this->assertSame(1, $linkBefore, 'precondition: one period_close journal_entry_link');

        $originalJeId = (int) DB::table('journal_entries')
            ->where('type', 'period_close')
            ->where('is_active_version', true)
            ->where('period_id', $period->id)
            ->value('id');

        // Capture the original authoritative state (HR68-01 invariant:
        // a failed reopen must roll back so these values remain unchanged).
        $originalIsActive = (bool) DB::table('journal_entries')->where('id', $originalJeId)->value('is_active_version');
        $originalLifecycle = DB::table('journal_entries')->where('id', $originalJeId)->value('lifecycle_status');
        $originalReversedAt = DB::table('journal_entries')->where('id', $originalJeId)->value('reversed_at');

        // Step 2 — stub PostingEngine::reverse to return FAILURE so the
        // ACC-002 correction boundary fails. The corrected reopenPeriod()
        // must throw (fail closed) rather than absorb the failure.
        $stub = \Mockery::mock(PostingEngine::class);
        $stub->shouldReceive('reverse')->andReturn(
            PostingResult::failure('HR68 induced reversal engine failure'),
        );
        $stub->shouldReceive('post')->andReturnUsing(function ($request) {
            return PostingResult::failure('unexpected post call');
        });
        app()->instance(PostingEngine::class, $stub);

        // PeriodCloseService caches PostingEngine via constructor injection.
        // Re-resolve the service AFTER binding the stub so reopenPeriod()
        // uses the stubbed engine (not the real one captured earlier).
        $service = app(PeriodCloseService::class);

        // Step 3 — attempt the reopen. Must throw.
        try {
            $service->reopenPeriod($period, (string) $this->user->id, 'HR68 fail-closed');
            $this->fail('reopenPeriod must throw (FAIL CLOSED) when ACC-002 reversal fails; it silently passed.');
        } catch (\RuntimeException $e) {
            // Fail-closed invariant: the exception propagates out of
            // reopenPeriod() (HR68-01). The message identifies the reversal
            // failure as the cause of the block.
            $this->assertStringContainsString('reversal', $e->getMessage(),
                'Fail-closed exception must reference the reversal failure.');
        }

        // Step 4 — zero authoritative delta (the outer DB::transaction
        // rolled back the whole reopen attempt).
        // (a) Original closing JE was NOT flipped by the failed reopen.
        $currentIsActive = (bool) DB::table('journal_entries')->where('id', $originalJeId)->value('is_active_version');
        $currentLifecycle = DB::table('journal_entries')->where('id', $originalJeId)->value('lifecycle_status');
        $currentReversedAt = DB::table('journal_entries')->where('id', $originalJeId)->value('reversed_at');
        $this->assertTrue($originalIsActive,
            'precondition: original closing JE must be is_active_version=true before reopen.');
        $this->assertSame($originalIsActive, $currentIsActive,
            'Original closing JE is_active_version must be unchanged by a FAILED reopen (HR68-01 invariant).');
        // The critical HR68-01 contract: a FAILED reversal must NOT flip the
        // original to lifecycle_status='reversed' (that is the success-branch
        // write, which the corrected code now performs AFTER reversal succeeds).
        $this->assertNotSame('reversed', $currentLifecycle,
            'Failed reopen must NOT flip lifecycle_status to reversed (HR68-01 invariant). lifecycle='
            .var_export($currentLifecycle, true));
        $this->assertNull($currentReversedAt,
            'Failed reopen must NOT set reversed_at (HR68-01 invariant). reversed_at='
            .var_export($currentReversedAt, true));

        // (b) The period_close journal_entry_link remains present — the
        // idempotency guard reference is coherent, not destroyed before
        // reversal succeeded.
        $linkAfter = (int) DB::table('journal_entry_links')
            ->where('source_document_type', 'period_close')
            ->where('source_document_id', $period->id)
            ->count();
        $this->assertSame(1, $linkAfter,
            'period_close journal_entry_link must remain on failed reopen (HR68-01 invariant).');

        // (c) No reversal journal entry was created.
        $this->assertSame(0, (int) DB::table('journal_entry_links')
            ->where('original_journal_entry_id', $originalJeId)
            ->where('relation_type', 'reversal')
            ->count(),
            'No reversal journal_entry_link may be persisted on a failed reopen (HR68-01 invariant).');

        // (d) Period remains in its pre-reopen state (closed) — the failed
        // reopen must not have flipped it to open (HR68-01 invariant:
        // no mixed authoritative state survives).
        $period->refresh();
        $this->assertSame('closed', $period->status,
            'Period must remain closed after a failed reopen (HR68-01 invariant). status='
            .var_export($period->status, true));
    }
}
