<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PostingAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

/**
 * RUNTIME VERIFICATION — CLAIM A
 *
 * Prior static-analysis claim: PostingAuditLog has no boot() guard blocking
 * update()/delete(), unlike the sibling AuditLog model (which does, and is
 * confirmed immutable by JournalImmutabilityTest::test_audit_log_cannot_be_updated /
 * test_audit_log_cannot_be_deleted).
 *
 * This test ACTUALLY EXECUTES update() and delete() against a real Postgres
 * row and observes what happens, instead of re-reading source.
 */
class PostingAuditLogRuntimeImmutabilityTest extends TenantTestCase
{
    protected int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTenantContext(\Illuminate\Support\Str::uuid()->toString());

        $user = User::factory()->create();
        $this->userId = $user->id;
    }

    public function test_update_on_posting_audit_log_actually_persists_the_change(): void
    {
        $log = PostingAuditLog::create([
            'document_type' => 'manual',
            'document_id' => 12345,
            'action' => 'post',
            'actor_user_id' => $this->userId,
            'payload_snapshot' => ['test' => true],
        ]);

        $id = $log->id;
        $this->assertSame('post', $log->fresh()->action);

        // ACTUALLY attempt to tamper with the audit trail.
        $exceptionThrown = null;
        try {
            $result = PostingAuditLog::find($id)->update(['action' => 'tampered_value']);
        } catch (\Throwable $e) {
            $exceptionThrown = $e;
        }

        $fresh = PostingAuditLog::find($id);

        if ($exceptionThrown !== null) {
            fwrite(STDERR, "\n[CLAIM A - update] Exception thrown: ".get_class($exceptionThrown).' - '.$exceptionThrown->getMessage()."\n");
            fwrite(STDERR, '[CLAIM A - update] action column after attempt: '.$fresh->action."\n");
        } else {
            fwrite(STDERR, "\n[CLAIM A - update] No exception thrown. update() returned: ".var_export($result, true)."\n");
            fwrite(STDERR, '[CLAIM A - update] action column after attempt: '.$fresh->action."\n");
        }

        // Report the ACTUAL observed outcome as the assertion (not an assumption).
        // If this fails, it means the model IS guarded (contradicts the static claim).
        // If it passes, the model is NOT guarded (confirms the static claim).
        $this->assertSame(
            'tampered_value',
            $fresh->action,
            'RUNTIME RESULT: update() did NOT persist a change to the action column — PostingAuditLog IS effectively immutable at runtime (contradicts static claim A).'
        );
        $this->assertNull($exceptionThrown, 'RUNTIME RESULT: update() threw an exception — PostingAuditLog IS guarded (contradicts static claim A).');
    }

    public function test_delete_on_posting_audit_log_actually_removes_the_row(): void
    {
        $log = PostingAuditLog::create([
            'document_type' => 'manual',
            'document_id' => 67890,
            'action' => 'post',
            'actor_user_id' => $this->userId,
            'payload_snapshot' => ['test' => true],
        ]);

        $id = $log->id;
        $this->assertNotNull(PostingAuditLog::find($id));

        $exceptionThrown = null;
        try {
            PostingAuditLog::find($id)->delete();
        } catch (\Throwable $e) {
            $exceptionThrown = $e;
        }

        $stillExists = PostingAuditLog::find($id) !== null;

        if ($exceptionThrown !== null) {
            fwrite(STDERR, "\n[CLAIM A - delete] Exception thrown: ".get_class($exceptionThrown).' - '.$exceptionThrown->getMessage()."\n");
        } else {
            fwrite(STDERR, "\n[CLAIM A - delete] No exception thrown. Row still exists after delete(): ".var_export($stillExists, true)."\n");
        }

        $this->assertFalse(
            $stillExists,
            'RUNTIME RESULT: delete() did NOT remove the row — PostingAuditLog IS effectively immutable at runtime (contradicts static claim A).'
        );
        $this->assertNull($exceptionThrown, 'RUNTIME RESULT: delete() threw an exception — PostingAuditLog IS guarded (contradicts static claim A).');
    }

    public function test_no_db_level_trigger_or_rule_protects_posting_audit_logs_table(): void
    {
        // Check for any Postgres trigger attached to posting_audit_logs.
        $triggers = DB::select(
            "SELECT tgname FROM pg_trigger t
             JOIN pg_class c ON c.oid = t.tgrelid
             WHERE c.relname = 'posting_audit_logs' AND NOT t.tgisinternal"
        );

        // Check for any Postgres RULE attached to posting_audit_logs.
        $rules = DB::select(
            "SELECT rulename FROM pg_rules WHERE tablename = 'posting_audit_logs'"
        );

        // Check the actual table privileges for the current role.
        $currentUser = DB::selectOne('SELECT current_user AS u')->u;
        $privileges = DB::select(
            "SELECT privilege_type FROM information_schema.role_table_grants
             WHERE table_name = 'posting_audit_logs' AND grantee = ?",
            [$currentUser]
        );

        fwrite(STDERR, "\n[CLAIM A - DB introspection] Triggers on posting_audit_logs: ".json_encode($triggers)."\n");
        fwrite(STDERR, '[CLAIM A - DB introspection] Rules on posting_audit_logs: '.json_encode($rules)."\n");
        fwrite(STDERR, '[CLAIM A - DB introspection] Privileges for current_user ('.$currentUser.') on posting_audit_logs: '.json_encode($privileges)."\n");

        $this->assertCount(0, $triggers, 'Expected NO triggers on posting_audit_logs (confirms static claim A: no DB-level trigger protection).');
        $this->assertCount(0, $rules, 'Expected NO rules on posting_audit_logs.');

        $privTypes = array_map(fn ($p) => $p->privilege_type, $privileges);
        $this->assertContains('UPDATE', $privTypes, 'Expected UPDATE to be GRANTed (not REVOKEd) to current role — confirms no DB-level REVOKE protection.');
        $this->assertContains('DELETE', $privTypes, 'Expected DELETE to be GRANTed (not REVOKEd) to current role — confirms no DB-level REVOKE protection.');
    }
}
