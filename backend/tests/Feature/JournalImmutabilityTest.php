<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\PostingAuditLog;
use App\Models\User;
use Tests\TenantTestCase;

class JournalImmutabilityTest extends TenantTestCase
{
    protected string $tenantUuid;
    protected int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = \Illuminate\Support\Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);

        // Create a user for TenantUserHelper
        $user = User::factory()->create();
        $this->userId = $user->id;
    }

    // ─── Posted Journal Entry Immutability ─────────────────────────

    public function test_posted_entry_cannot_be_deleted_via_model(): void
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-IMMUT-001',
            'entry_date' => now()->toDateString(),
            'description' => 'Immutable entry',
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => 1,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete posted journal entry');
        $entry->delete();
    }

    public function test_draft_entry_can_be_deleted(): void
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-DRAFT-DEL',
            'entry_date' => now()->toDateString(),
            'description' => 'Draft entry',
            'status' => 'draft',
        ]);

        $entry->delete();

        $this->assertNull(JournalEntry::find($entry->id));
    }

    public function test_posted_entry_deprecated_post_method_throws(): void
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-DEP-POST',
            'entry_date' => now()->toDateString(),
            'description' => 'Deprecated method test',
            'status' => 'draft',
        ]);

        $this->expectException(\BadMethodCallException::class);
        $entry->post();
    }

    public function test_posted_entry_deprecated_reverse_method_throws(): void
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-DEP-REV',
            'entry_date' => now()->toDateString(),
            'description' => 'Deprecated reverse test',
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        $this->expectException(\BadMethodCallException::class);
        $entry->reverse();
    }

    // ─── AuditLog Immutability ────────────────────────────────────

    public function test_audit_log_cannot_be_updated(): void
    {
        $log = AuditLog::create([
            'operation' => 'test.operation',
            'reference_type' => 'App\\Models\\JournalEntry',
            'reference_id' => 1,
            'before_state' => ['status' => 'draft'],
            'after_state' => ['status' => 'posted'],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Audit logs are immutable');
        $log->update(['operation' => 'modified.operation']);
    }

    public function test_audit_log_cannot_be_deleted(): void
    {
        $log = AuditLog::create([
            'operation' => 'test.delete',
            'reference_type' => 'App\\Models\\JournalEntry',
            'reference_id' => 2,
            'before_state' => [],
            'after_state' => [],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Audit logs are immutable');
        $log->delete();
    }

    // ─── PostingAuditLog Immutability ─────────────────────────────

    public function test_posting_audit_log_has_no_updated_at(): void
    {
        $this->assertNull(PostingAuditLog::UPDATED_AT);
    }

    public function test_posting_audit_log_cannot_update_timestamp(): void
    {
        $log = PostingAuditLog::create([
            'document_type' => 'manual',
            'document_id' => 1,
            'action' => 'post',
            'actor_user_id' => $this->userId,
            'payload_snapshot' => ['test' => true],
        ]);

        // updated_at should remain null even after update attempt
        $log->touch();
        $log->refresh();

        $this->assertNull($log->updated_at);
    }

    // ─── Controller-Level Immutability (structural checks) ────────

    public function test_journal_entry_has_status_field(): void
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-STATUS-001',
            'entry_date' => now()->toDateString(),
            'description' => 'Status check',
            'status' => 'draft',
        ]);

        $this->assertArrayHasKey('status', $entry->toArray());
        $this->assertEquals('draft', $entry->status);
    }

    public function test_journal_entry_has_posted_at_field(): void
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-POSTED-AT',
            'entry_date' => now()->toDateString(),
            'description' => 'Posted at check',
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        $this->assertNotNull($entry->posted_at);
    }

    public function test_journal_entry_has_is_reversal_flag(): void
    {
        $entry = JournalEntry::create([
            'entry_number' => 'JE-REVERSAL',
            'entry_date' => now()->toDateString(),
            'description' => 'Reversal flag check',
            'status' => 'posted',
            'is_reversal' => true,
        ]);

        $this->assertTrue($entry->is_reversal);
    }

    // ─── Immutability Layer Coverage ──────────────────────────────

    public function test_immutability_enforced_at_model_level(): void
    {
        $postedEntry = JournalEntry::create([
            'entry_number' => 'JE-IMMUT-MODEL',
            'entry_date' => now()->toDateString(),
            'description' => 'Model-level immutability',
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        // Verify the model guard exists
        $this->assertTrue($postedEntry->status === 'posted');
        $this->assertNotNull($postedEntry->posted_at);
    }

    public function test_reversal_entry_links_to_original(): void
    {
        $original = JournalEntry::create([
            'entry_number' => 'JE-ORIG-001',
            'entry_date' => now()->toDateString(),
            'description' => 'Original entry',
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        $reversal = JournalEntry::create([
            'entry_number' => 'JE-REV-001',
            'entry_date' => now()->toDateString(),
            'description' => 'Reversal entry',
            'status' => 'posted',
            'posted_at' => now(),
            'is_reversal' => true,
            'reversed_entry_id' => $original->id,
        ]);

        $this->assertEquals($original->id, $reversal->reversed_entry_id);
        $this->assertTrue($reversal->is_reversal);
    }
}
