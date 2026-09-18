<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #77 (CRITICAL) — Database-level row-level security for
 * communication_messages internal notes.
 *
 * Defence in depth: even if an attacker bypasses the application-layer filter
 * in CommunicationEngine::getThread (e.g. via raw SQL injection, ORM
 * misconfiguration, or middleware bypass), the DB still rejects SELECT of
 * internal_note rows by non-staff callers.
 *
 * The application-layer filter uses the current authenticated user's role
 * (resolved via Spatie roles / user_company_access) and treats null as
 * non-staff. The DB policy mirrors this contract: a non-staff role sees
 * non-internal-note rows only. The author always sees their own internal
 * notes (consistent with the application filter).
 *
 * Forward-only: down() drops the policy and disables RLS.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('communication_messages')) {
            return; // Module not provisioned in this schema; nothing to do.
        }

        // Helper SQL function: returns the current authenticated user's role
        // from the central user_company_access table, or 'anonymous' if no
        // row matches. The role is stored lowercased for comparison.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION communication_current_role()
            RETURNS text AS $$
            DECLARE
                v_role text;
            BEGIN
                SELECT lower(role) INTO v_role
                FROM user_company_access
                WHERE user_id = NULLIF(current_setting('app.current_user_id', true), '')::bigint
                ORDER BY is_default DESC
                LIMIT 1;
                RETURN COALESCE(v_role, 'anonymous');
            END;
            $$ LANGUAGE plpgsql STABLE;
        SQL);

        // Helper: returns current authenticated user id from session setting.
        // Laravel sets this via a DB::statement() at request start (see
        // SetTenantFromUserPreference middleware extension; the policy still
        // works as defence in depth even if unset — it falls back to
        // 'anonymous' role which fails closed).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION communication_current_user_id()
            RETURNS bigint AS $$
            BEGIN
                RETURN NULLIF(current_setting('app.current_user_id', true), '')::bigint;
            END;
            $$ LANGUAGE plpgsql STABLE;
        SQL);

        // Enable RLS on the table.
        DB::statement('ALTER TABLE communication_messages ENABLE ROW LEVEL SECURITY');

        // Force RLS even for table owner (defence in depth).
        DB::statement('ALTER TABLE communication_messages FORCE ROW LEVEL SECURITY');

        // Drop existing policy if present (idempotent re-run).
        DB::statement('DROP POLICY IF EXISTS internal_notes_visibility ON communication_messages');

        // Policy: SELECT of internal_note rows is restricted to:
        //   - the author (author_id = current user)
        //   - users with staff roles (owner/admin/manager/staff)
        // All other rows are unrestricted.
        DB::statement(<<<'SQL'
            CREATE POLICY internal_notes_visibility ON communication_messages
            AS RESTRICTIVE
            FOR SELECT
            USING (
                message_type <> 'internal_note'
                OR author_id = communication_current_user_id()
                OR communication_current_role() IN ('owner', 'admin', 'manager', 'staff')
            );
        SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('communication_messages')) {
            return;
        }
        DB::statement('DROP POLICY IF EXISTS internal_notes_visibility ON communication_messages');
        DB::statement('ALTER TABLE communication_messages NO FORCE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE communication_messages DISABLE ROW LEVEL SECURITY');
        DB::statement('DROP FUNCTION IF EXISTS communication_current_role()');
        DB::statement('DROP FUNCTION IF EXISTS communication_current_user_id()');
    }
};
