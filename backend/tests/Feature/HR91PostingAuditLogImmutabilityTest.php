<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PostingAuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TenantTestCase;

/**
 * Issue #91 (CRITICAL, AUDIT-1) — Runtime proof of posting audit log immutability.
 *
 * Verifies that BOTH the Eloquent boot() guard AND the DB-level triggers
 * block UPDATE and DELETE on posting_audit_logs.
 */
class HR91PostingAuditLogImmutabilityTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
    }

    private function createAuditLog(): PostingAuditLog
    {
        return PostingAuditLog::create([
            'document_type' => 'journal_entry',
            'document_id' => 1,
            'journal_entry_id' => 1,
            'action' => 'posted',
            'actor_user_id' => 1,
            'reason_code' => 'TEST_HR91',
            'payload_snapshot' => ['note' => 'initial'],
        ]);
    }

    // ─── Eloquent layer guards ─────────────────────────────────────

    public function test_eloquent_update_throws_runtime_exception(): void
    {
        if (! Schema::hasTable('posting_audit_logs')) {
            $this->markTestSkipped('posting_audit_logs not provisioned');
        }

        $log = $this->createAuditLog();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PostingAuditLog records are immutable and cannot be updated');
        $log->update(['action' => 'tampered']);
    }

    public function test_eloquent_delete_throws_runtime_exception(): void
    {
        if (! Schema::hasTable('posting_audit_logs')) {
            $this->markTestSkipped('posting_audit_logs not provisioned');
        }

        $log = $this->createAuditLog();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PostingAuditLog records are immutable and cannot be deleted');
        $log->delete();
    }

    public function test_eloquent_create_succeeds(): void
    {
        if (! Schema::hasTable('posting_audit_logs')) {
            $this->markTestSkipped('posting_audit_logs not provisioned');
        }

        // Inserts must still succeed — the guard is on update/delete only.
        $log = $this->createAuditLog();
        $this->assertNotNull($log->id);
        $this->assertSame('TEST_HR91', $log->reason_code);
    }

    // ─── DB-level guards (defence in depth) ─────────────────────────

    public function test_db_layer_update_throws_via_trigger(): void
    {
        if (! Schema::hasTable('posting_audit_logs')) {
            $this->markTestSkipped('posting_audit_logs not provisioned');
        }

        $log = $this->createAuditLog();
        $logId = $log->id;
        $originalAction = $log->action;

        // Bypass Eloquent entirely — raw SQL update. The DB trigger must reject.
        // Use a savepoint so the aborted sub-statement doesn't poison the
        // outer RefreshDatabase transaction.
        $threw = false;
        DB::beginTransaction();
        try {
            try {
                DB::update(
                    'UPDATE posting_audit_logs SET action = ? WHERE id = ?',
                    ['tampered_via_raw_sql', $logId]
                );
            } catch (\Throwable $e) {
                $threw = true;
                $this->assertStringContainsString('posting_audit_logs is append-only', $e->getMessage());
            }
            DB::rollBack();
        } catch (\Throwable $outer) {
            DB::rollBack();
            throw $outer;
        }

        // Re-open the outer transaction context (RefreshDatabase expects
        // the test to leave a usable transaction behind).
        DB::beginTransaction();

        $this->assertTrue($threw, 'Raw UPDATE must be blocked by DB trigger');

        // Verify the row was NOT modified.
        $fresh = PostingAuditLog::find($logId);
        $this->assertNotNull($fresh, 'Row must still exist after blocked UPDATE');
        $this->assertSame($originalAction, $fresh->action, 'Row must remain unchanged after blocked UPDATE');
    }

    public function test_db_layer_delete_throws_via_trigger(): void
    {
        if (! Schema::hasTable('posting_audit_logs')) {
            $this->markTestSkipped('posting_audit_logs not provisioned');
        }

        $log = $this->createAuditLog();
        $logId = $log->id;

        // Bypass Eloquent entirely — raw SQL delete. The DB trigger must reject.
        $threw = false;
        DB::beginTransaction();
        try {
            try {
                DB::delete('DELETE FROM posting_audit_logs WHERE id = ?', [$logId]);
            } catch (\Throwable $e) {
                $threw = true;
                $this->assertStringContainsString('posting_audit_logs is append-only', $e->getMessage());
            }
            DB::rollBack();
        } catch (\Throwable $outer) {
            DB::rollBack();
            throw $outer;
        }
        DB::beginTransaction();

        $this->assertTrue($threw, 'Raw DELETE must be blocked by DB trigger');

        // Verify the row still exists.
        $this->assertNotNull(PostingAuditLog::find($logId), 'Row must still exist after blocked DELETE');
    }

    public function test_db_triggers_are_installed(): void
    {
        if (! Schema::hasTable('posting_audit_logs')) {
            $this->markTestSkipped('posting_audit_logs not provisioned');
        }

        // Confirm the triggers exist via pg_trigger.
        $updateTrigger = DB::scalar("
            SELECT tgname FROM pg_trigger
            WHERE tgrelid = 'posting_audit_logs'::regclass
              AND tgname = 'posting_audit_logs_no_update'
              AND NOT tgisinternal
        ");
        $this->assertSame('posting_audit_logs_no_update', $updateTrigger);

        $deleteTrigger = DB::scalar("
            SELECT tgname FROM pg_trigger
            WHERE tgrelid = 'posting_audit_logs'::regclass
              AND tgname = 'posting_audit_logs_no_delete'
              AND NOT tgisinternal
        ");
        $this->assertSame('posting_audit_logs_no_delete', $deleteTrigger);
    }
}
