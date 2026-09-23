<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\FiscalPeriod;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\PeriodCloseService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #68 (hostile re-review HR68-01): real ACC-002 correction-boundary
 * atomicity evidence — no mocks.
 *
 * The existing HR68 fail-closed test stubs PostingEngine::reverse. This test
 * induces a DETERMINISTIC failure at the real available ACC-002 correction
 * boundary: the closing journal entry is in a non-posted state, so the real
 * ReversalCorrectionEngine::fullReversal blocks with ORIGINAL_NOT_REVERSIBLE.
 *
 * Proves:
 *  1. reopenPeriod fails closed (throws) at the real boundary;
 *  2. period remains closed;
 *  3. the original closing JE remains authoritative (active, not reversed);
 *  4. the posting reference remains coherent (present, not superseded);
 *  5. no reversal journal entry survives;
 *  6. no false reopen success evidence is emitted.
 */
class HR68RealBoundaryAtomicityTest extends TenantTestCase
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

    private function account(string $type): int
    {
        return (int) DB::table('chart_of_accounts')->insertGetId([
            'code' => 'HR68A-'.strtoupper($type[0]).'-'.Str::random(4),
            'name' => "HR68 atomic {$type} ".Str::random(4),
            'type' => $type,
            'account_type' => $type,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_real_acc002_boundary_failure_leaves_zero_mixed_state(): void
    {
        $period = FiscalPeriod::create([
            'name' => 'HR68 real boundary', 'code' => 'HR68RB-'.Str::random(6),
            'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
            'period_type' => 'yearly', 'fiscal_year' => 2026,
            'status' => 'open', 'created_by' => $this->user->id,
        ]);

        $revenue = $this->account('revenue');
        $cash = $this->account('asset');

        // Seed a posted revenue JE inside the period.
        $rev = app(PostingEngineInterface::class)->post(new PostingRequest(
            documentType: 'sales_invoice',
            documentId: 0,
            postingDate: '2026-06-15',
            description: 'HR68 atomic revenue',
            lines: [
                new PostingRequestLine('revenue', $revenue, 0, 500, 'Revenue'),
                new PostingRequestLine('asset', $cash, 500, 0, 'Cash'),
            ],
            reference: 'HR68-ATOM-'.Str::random(6),
            userId: (int) $this->user->id,
        ));
        $this->assertTrue($rev->success, "Revenue posted: {$rev->error}");

        // Close the period through the real path (creates the closing JE + link).
        $service = app(PeriodCloseService::class);
        $service->closePeriod($period->refresh(), (string) $this->user->id);
        $this->assertSame('closed', $period->fresh()->status);

        $closingId = (int) DB::table('journal_entries')
            ->where('type', 'period_close')->where('is_active_version', true)
            ->where('period_id', $period->id)->value('id');
        $this->assertGreaterThan(0, $closingId, 'precondition: an active closing JE exists');

        // ── Induce a deterministic failure at the REAL ACC-002 boundary ──
        // fullReversal's own eligibility gate (Gate 1) blocks a non-posted
        // original with ORIGINAL_NOT_REVERSIBLE — no mocks involved.
        JournalEntry::whereKey($closingId)->update(['status' => 'draft']);

        $threw = false;
        try {
            $service->reopenPeriod($period->fresh(), (string) $this->user->id, 'HR68 real boundary reopen');
        } catch (\Throwable $e) {
            $threw = true;
            $this->assertStringContainsString('reversal', strtolower($e->getMessage()));
        }

        $this->assertTrue($threw, 'reopenPeriod must fail closed at the real ACC-002 boundary.');

        // ── Zero mixed authoritative state ──
        $this->assertSame('closed', $period->fresh()->status, 'Period must remain closed.');

        $closing = JournalEntry::whereKey($closingId)->first();
        $this->assertTrue((bool) $closing->is_active_version, 'Original closing JE must remain authoritative.');
        $this->assertNotSame('reversed', $closing->lifecycle_status);
        $this->assertNull($closing->reversed_at);

        $link = DB::table('journal_entry_links')
            ->where('source_document_type', 'period_close')
            ->where('source_document_id', $period->id)
            ->where('relation_type', 'post')
            ->first();
        $this->assertNotNull($link, 'Posting reference must remain coherent.');
        $this->assertNull($link->superseded_at, 'Posting reference must not be retired on failure.');

        $this->assertSame(0, JournalEntry::where('reversed_entry_id', $closingId)->count(),
            'No reversal journal entry may survive a failed reopen.');

        $this->assertSame(0, DB::table('reversal_relationships')->where('original_journal_entry_id', $closingId)->count(),
            'No reversal relationship may survive a failed reopen.');

        $this->assertSame(0, DB::table('state_transitions')
            ->where('aggregate_type', 'JournalEntry')
            ->where('aggregate_id', (string) $closingId)
            ->where('to_state', 'REVERSED')
            ->count(), 'No false REVERSED transition evidence may be emitted.');
    }
}
