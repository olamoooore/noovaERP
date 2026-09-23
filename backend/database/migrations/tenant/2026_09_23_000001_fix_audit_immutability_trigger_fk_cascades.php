<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #129 (hostile re-review 2026-09-23): the DB-level immutability trigger
 * on product_audit_logs blocked FK-initiated writes — DELETE FROM products
 * (ON DELETE CASCADE) and DELETE FROM users (ON DELETE SET NULL) failed because
 * the audit trigger raised on the cascaded row. TRUNCATE also bypassed the row
 * triggers entirely.
 *
 * Fix (product_audit_logs): allow a DELETE when the referenced parent product
 * is already gone (i.e. this row is being removed by the FK cascade), and allow
 * an UPDATE whose only change is an FK column becoming NULL (the FK SET NULL
 * action). Direct UPDATE/DELETE still raise. posting_audit_logs has no FKs, so
 * it keeps the strict guard; both tables gain a statement-level TRUNCATE guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_audit_logs')) {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION product_audit_logs_block_modify()
                RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        -- FK cascade: the referenced product no longer exists.
                        IF NOT EXISTS (SELECT 1 FROM products WHERE id = OLD.product_id) THEN
                            RETURN OLD;
                        END IF;

                        RAISE EXCEPTION 'product_audit_logs is append-only: DELETE on this table is not permitted'
                            USING ERRCODE = 'P0001';
                    END IF;

                    IF TG_OP = 'UPDATE' THEN
                        -- FK SET NULL: every non-FK column is unchanged and the
                        -- FK columns only changed (if at all) to NULL.
                        IF (to_jsonb(NEW) - 'product_id' - 'user_id') = (to_jsonb(OLD) - 'product_id' - 'user_id')
                           AND (NEW.product_id IS NULL OR NEW.product_id = OLD.product_id)
                           AND (NEW.user_id IS NULL OR NEW.user_id = OLD.user_id) THEN
                            RETURN NEW;
                        END IF;

                        RAISE EXCEPTION 'product_audit_logs is append-only: UPDATE on this table is not permitted'
                            USING ERRCODE = 'P0001';
                    END IF;

                    RAISE EXCEPTION 'product_audit_logs is append-only: % on this table is not permitted', TG_OP
                        USING ERRCODE = 'P0001';
                END;
                $$ LANGUAGE plpgsql;
            SQL);

            foreach (['no_update' => 'BEFORE UPDATE ON product_audit_logs FOR EACH ROW',
                      'no_delete' => 'BEFORE DELETE ON product_audit_logs FOR EACH ROW',
                      'no_truncate' => 'BEFORE TRUNCATE ON product_audit_logs FOR EACH STATEMENT'] as $name => $clause) {
                DB::statement("DROP TRIGGER IF EXISTS product_audit_logs_{$name} ON product_audit_logs");
                DB::statement("
                    CREATE TRIGGER product_audit_logs_{$name}
                    {$clause}
                    EXECUTE FUNCTION product_audit_logs_block_modify();
                ");
            }
        }

        if (Schema::hasTable('posting_audit_logs')) {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION posting_audit_logs_block_modify()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'posting_audit_logs is append-only: % on this table is not permitted', TG_OP
                        USING ERRCODE = 'P0001';
                END;
                $$ LANGUAGE plpgsql;
            SQL);

            DB::statement('DROP TRIGGER IF EXISTS posting_audit_logs_no_truncate ON posting_audit_logs');
            DB::statement('
                CREATE TRIGGER posting_audit_logs_no_truncate
                BEFORE TRUNCATE ON posting_audit_logs
                FOR EACH STATEMENT
                EXECUTE FUNCTION posting_audit_logs_block_modify();
            ');
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS product_audit_logs_no_truncate ON product_audit_logs');
        DB::statement('DROP TRIGGER IF EXISTS posting_audit_logs_no_truncate ON posting_audit_logs');
    }
};
