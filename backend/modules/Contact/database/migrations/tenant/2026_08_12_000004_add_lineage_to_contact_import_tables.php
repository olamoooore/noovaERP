<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-003A: migration/import lineage on Contact import staging (CT-VEN-022).
 *
 * batch id doubles as migration_batch_id; source_system identifies the
 * approved source (e.g. acme_customer_master); staging rows keep a stable
 * source_record_key, payload hash and the unresolved original legacy values
 * for reconciliation/rollback (CT-VEN-014).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_import_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('contact_import_batches', 'source_system')) {
                $table->string('source_system', 100)->nullable()->after('file_hash');
            }
        });

        Schema::table('contact_import_staging', function (Blueprint $table) {
            if (! Schema::hasColumn('contact_import_staging', 'source_record_key')) {
                $table->string('source_record_key', 255)->nullable()->after('row_number');
            }
            if (! Schema::hasColumn('contact_import_staging', 'source_payload_hash')) {
                $table->string('source_payload_hash', 64)->nullable()->after('source_record_key');
            }
            if (! Schema::hasColumn('contact_import_staging', 'original_legacy_values')) {
                $table->json('original_legacy_values')->nullable()->after('mapped_data');
            }
        });

        Schema::table('contact_import_staging', function (Blueprint $table) {
            $table->index(['batch_id', 'source_record_key'], 'contact_import_staging_source_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contact_import_staging', function (Blueprint $table) {
            $table->dropIndex('contact_import_staging_source_idx');
            $table->dropColumn(['source_record_key', 'source_payload_hash', 'original_legacy_values']);
        });

        Schema::table('contact_import_batches', function (Blueprint $table) {
            $table->dropColumn('source_system');
        });
    }
};
