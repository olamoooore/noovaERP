<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #129 (MEDIUM) — defence-in-depth DB-level immutability for
 * product_audit_logs, mirroring the #91 treatment of posting_audit_logs. The
 * application guard (ProductAuditLog::boot) blocks Eloquent writes; this
 * trigger blocks raw SQL / DB::table() writes too.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_audit_logs')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION product_audit_logs_block_modify()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'product_audit_logs is append-only: % on this table is not permitted', TG_OP
                    USING ERRCODE = 'P0001';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS product_audit_logs_no_update ON product_audit_logs');
        DB::statement(<<<'SQL'
            CREATE TRIGGER product_audit_logs_no_update
            BEFORE UPDATE ON product_audit_logs
            FOR EACH ROW
            EXECUTE FUNCTION product_audit_logs_block_modify();
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS product_audit_logs_no_delete ON product_audit_logs');
        DB::statement(<<<'SQL'
            CREATE TRIGGER product_audit_logs_no_delete
            BEFORE DELETE ON product_audit_logs
            FOR EACH ROW
            EXECUTE FUNCTION product_audit_logs_block_modify();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS product_audit_logs_no_update ON product_audit_logs');
        DB::statement('DROP TRIGGER IF EXISTS product_audit_logs_no_delete ON product_audit_logs');
        DB::statement('DROP FUNCTION IF EXISTS product_audit_logs_block_modify()');
    }
};
