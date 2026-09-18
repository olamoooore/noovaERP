<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Communication\Models\CommunicationMessage;
use Tests\TenantTestCase;

/**
 * Issue #77 (CRITICAL) — Internal notes must NOT be visible to non-staff users.
 *
 * Verifies:
 *   1. Application-layer filter (CommunicationEngine::getThread) excludes
 *      internal_note rows for non-staff callers (or null user context).
 *   2. Authors can always see their own internal notes regardless of role.
 *   3. Staff/Admin/Manager roles can see ALL internal notes.
 *   4. The DB-level row-level security policy exists and is enabled.
 */
class HR77CommunicationInternalNoteIsolationTest extends TenantTestCase
{
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantId = (string) Str::uuid();
        $this->bindTenantContext($this->tenantId);
    }

    private function createMessage(array $attrs): CommunicationMessage
    {
        return CommunicationMessage::create(array_merge([
            'tenant_id' => $this->tenantId,
            'model' => 'TestRecord',
            'record_id' => 1,
            'subtype' => 'comment',
            'message_type' => CommunicationMessage::TYPE_COMMENT,
            'body' => 'Body',
            'author_id' => 1,
            'status' => 'sent',
        ], $attrs));
    }

    // ─── 1. Non-staff cannot see internal notes ─────────────────────

    public function test_non_staff_role_does_not_see_internal_notes(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('communication_messages')) {
            $this->markTestSkipped('communication_messages not provisioned');
        }

        $this->createMessage([
            'message_type' => CommunicationMessage::TYPE_COMMENT,
            'body' => 'Visible comment',
        ]);
        $this->createMessage([
            'message_type' => CommunicationMessage::TYPE_INTERNAL_NOTE,
            'body' => 'CONFIDENTIAL: do not show',
        ]);

        // Resolve the engine — use the service container binding.
        $engine = app(\Modules\Communication\Services\CommunicationEngine::class);

        $reflection = new \ReflectionMethod($engine, 'getThread');
        $result = $reflection->invoke(
            $engine,
            'TestRecord',
            1,
            null,
            50,
            null,
            null, // currentUserId = null (no auth)
            null, // userRole = null (no role)
        );

        $bodies = $result->pluck('body')->toArray();
        $this->assertContains('Visible comment', $bodies);
        $this->assertNotContains('CONFIDENTIAL: do not show', $bodies, 'Internal note must NOT be visible to non-staff');
    }

    public function test_sales_role_does_not_see_internal_notes(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('communication_messages')) {
            $this->markTestSkipped('communication_messages not provisioned');
        }

        $this->createMessage([
            'message_type' => CommunicationMessage::TYPE_COMMENT,
            'body' => 'Visible',
        ]);
        $this->createMessage([
            'message_type' => CommunicationMessage::TYPE_INTERNAL_NOTE,
            'body' => 'INTERNAL',
        ]);

        $engine = app(\Modules\Communication\Services\CommunicationEngine::class);
        $reflection = new \ReflectionMethod($engine, 'getThread');
        $result = $reflection->invoke(
            $engine, 'TestRecord', 1, null, 50, null, null, 'sales'
        );

        $this->assertNotContains('INTERNAL', $result->pluck('body')->toArray());
    }

    // ─── 2. Authors can see their own internal notes ─────────────────

    public function test_author_can_see_own_internal_notes(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('communication_messages')) {
            $this->markTestSkipped('communication_messages not provisioned');
        }

        // Create a real tenant first so user_company_access FK is satisfied.
        $tenantPk = DB::table('tenants')->insertGetId([
            'id' => $this->tenantId,
            'name' => 'HR77 Test Tenant',
            'email' => 'hr77-'.uniqid().'@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = \App\Models\User::factory()->create();
        DB::table('user_company_access')->insertOrIgnore([
            'user_id' => $user->id,
            'tenant_id' => $tenantPk,
            'role' => 'sales',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->createMessage([
            'message_type' => CommunicationMessage::TYPE_INTERNAL_NOTE,
            'body' => 'MY OWN NOTE',
            'author_id' => $user->id,
        ]);

        $engine = app(\Modules\Communication\Services\CommunicationEngine::class);
        $reflection = new \ReflectionMethod($engine, 'getThread');
        $result = $reflection->invoke(
            $engine, 'TestRecord', 1, null, 50, null, $user->id, 'sales'
        );

        $this->assertContains('MY OWN NOTE', $result->pluck('body')->toArray());
    }

    // ─── 3. Staff roles can see all internal notes ──────────────────

    public function test_staff_role_sees_all_internal_notes(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('communication_messages')) {
            $this->markTestSkipped('communication_messages not provisioned');
        }

        $this->createMessage([
            'message_type' => CommunicationMessage::TYPE_INTERNAL_NOTE,
            'body' => 'ADMIN VIEW',
        ]);

        $engine = app(\Modules\Communication\Services\CommunicationEngine::class);
        foreach (['admin', 'owner', 'manager', 'staff'] as $role) {
            $reflection = new \ReflectionMethod($engine, 'getThread');
            $result = $reflection->invoke(
                $engine, 'TestRecord', 1, null, 50, null, null, $role
            );
            $this->assertContains(
                'ADMIN VIEW',
                $result->pluck('body')->toArray(),
                "Staff role '{$role}' must see internal notes"
            );
        }
    }

    // ─── 4. DB-level RLS policy is installed ────────────────────────

    public function test_db_rls_policy_is_installed(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('communication_messages')) {
            $this->markTestSkipped('communication_messages not provisioned');
        }

        $policy = DB::scalar("
            SELECT policyname FROM pg_policies
            WHERE tablename = 'communication_messages'
              AND policyname = 'internal_notes_visibility'
        ");
        $this->assertSame('internal_notes_visibility', $policy);

        $rlsEnabled = DB::scalar("
            SELECT relrowsecurity FROM pg_class
            WHERE relname = 'communication_messages'
        ");
        $this->assertTrue((bool) $rlsEnabled, 'RLS must be enabled on communication_messages');
    }
}
