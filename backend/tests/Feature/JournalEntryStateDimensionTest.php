<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * ACC-RM-010 / ACC-RM-011 (ADR-005 §11.5) — the four journal state
 * dimensions must be distinct, defaulted on create, and kept in sync
 * ONLY from the legacy `status` column when the caller has not written
 * an explicit dimension value.
 *
 * This is the runtime test demanded by DAILY_REPORT_2026-09-01 §H risk 2:
 * until now the boot() creating/saving hooks had no direct test asserting
 * the dimension values on the created/updated row.
 */
class JournalEntryStateDimensionTest extends TenantTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTenantContext((string) Str::uuid());
        $this->user = User::factory()->create();
    }

    private function makeEntry(array $overrides = []): JournalEntry
    {
        return JournalEntry::create(array_merge([
            'entry_number' => 'SD-'.Str::uuid(),
            'entry_date' => '2026-09-01',
            'description' => 'State dimension test entry',
            'type' => 'manual',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ], $overrides));
    }

    public function test_creating_draft_entry_defaults_all_four_dimensions(): void
    {
        $entry = $this->makeEntry(['status' => 'draft']);

        $this->assertSame('NOT_POSTED', $entry->fresh()->posting_state);
        $this->assertSame('DRAFT', $entry->fresh()->journal_business_state);
        $this->assertSame('NONE', $entry->fresh()->workflow_state);
        $this->assertSame('NOT_REQUIRED', $entry->fresh()->approval_state);
    }

    public function test_legacy_status_posted_on_create_syncs_posting_state(): void
    {
        $entry = $this->makeEntry([
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => $this->user->id,
        ]);

        // saving() fires before creating() on first insert, so the legacy
        // sync branch wins over the create default here.
        $this->assertSame('POSTED', $entry->fresh()->posting_state);
        // 'posted' is not draft/cancelled → business dimension lands on SUBMITTED.
        $this->assertSame('SUBMITTED', $entry->fresh()->journal_business_state);
    }

    public function test_legacy_status_update_syncs_both_state_dimensions(): void
    {
        $entry = $this->makeEntry(['status' => 'draft']);
        $this->assertSame('NOT_POSTED', $entry->fresh()->posting_state);

        // Legacy caller flips only `status` → the saving hook must mirror
        // it into posting_state + journal_business_state.
        $entry->update([
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => $this->user->id,
        ]);

        $fresh = $entry->fresh();
        $this->assertSame('POSTED', $fresh->posting_state);
        $this->assertSame('SUBMITTED', $fresh->journal_business_state);
    }

    public function test_reversal_via_explicit_dimension_write_survives_legacy_sync(): void
    {
        // The legacy `status` column is CHECK-constrained to draft|posted —
        // reversal state is carried by the new dimensions (exactly how
        // ReversalCorrectionEngine records it: business dimension 'REVERSED').
        $entry = $this->makeEntry([
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => $this->user->id,
        ]);

        $entry->journal_business_state = 'REVERSED';
        $entry->posting_state = 'REVERSED';
        $entry->reversed_at = now();
        $entry->save();

        $fresh = $entry->fresh();
        $this->assertSame('REVERSED', $fresh->posting_state);
        $this->assertSame('REVERSED', $fresh->journal_business_state);
        $this->assertSame('posted', $fresh->status);
    }

    public function test_explicit_dimension_override_is_never_clobbered_by_legacy_sync(): void
    {
        // The dimension columns are intentionally NOT mass-assignable; the
        // caller must set them as model attributes (this is also how the
        // PostingEngine writes them).
        $entry = $this->makeEntry(['status' => 'draft']);

        $entry->posting_state = 'POSTING';
        $entry->status = 'posted';
        $entry->posted_at = now();
        $entry->posted_by = $this->user->id;
        $entry->save();

        $fresh = $entry->fresh();
        // posting_state was explicitly dirty → sync branch must NOT overwrite.
        $this->assertSame('POSTING', $fresh->posting_state);

        // A later untouched-status save does not re-sync anything.
        $fresh->journal_business_state = 'SUBMITTED';
        $fresh->save();
        $this->assertSame('POSTING', $fresh->fresh()->posting_state);
    }

    public function test_dimensions_are_independent_and_persisted_together(): void
    {
        $entry = $this->makeEntry(['status' => 'draft']);

        $entry->workflow_state = 'IN_REVIEW';
        $entry->approval_state = 'PENDING';
        $entry->save();

        $fresh = $entry->fresh();
        $this->assertSame('IN_REVIEW', $fresh->workflow_state);
        $this->assertSame('PENDING', $fresh->approval_state);
        // untouched dimensions keep their create-time defaults
        $this->assertSame('NOT_POSTED', $fresh->posting_state);
        $this->assertSame('DRAFT', $fresh->journal_business_state);
    }
}
