<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MRD gap review G-04/G-05: tax_determination_results lives in the central
 * schema shared by tenants, so (tenant_id, source type/id, result_version)
 * must be unique and the input fingerprint must be stored for idempotent
 * recomputation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tax_determination_results')) {
            return;
        }

        Schema::table('tax_determination_results', function ($table) {
            if (! Schema::hasColumn('tax_determination_results', 'input_hash')) {
                $table->string('input_hash', 64)->nullable()->after('tax_lines');
            }
        });

        $indexed = DB::selectOne("
            select 1 from pg_indexes
            where schemaname = current_schema()
              and tablename = 'tax_determination_results'
              and indexname = 'tax_determination_tenant_source_unique'
        ") !== null;

        if (! $indexed) {
            Schema::table('tax_determination_results', function ($table) {
                $table->unique(['tenant_id', 'source_record_type', 'source_record_id', 'result_version'], 'tax_determination_tenant_source_unique');
            });
        }
    }

    public function down(): void
    {
        // Forward-only.
    }
};
