<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5 publish/rollback truthfulness (MRD Appendix-F P5).
 *
 * Rollback was file-copy-only while the public route serves from the
 * database — so a "rollback" changed nothing visitors saw. For a rollback
 * to be honest we must know exactly WHICH page versions were live at each
 * publish: record the snapshot map (page_id -> page_version_id) on the log,
 * plus the provenance of a rollback-derived publish.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wb_publish_logs', function (Blueprint $table) {
            $table->jsonb('page_versions')->nullable()->after('output_path');
            $table->unsignedInteger('rollback_of')->nullable()->after('page_versions');
        });
    }

    public function down(): void
    {
        Schema::table('wb_publish_logs', function (Blueprint $table) {
            $table->dropColumn(['page_versions', 'rollback_of']);
        });
    }
};
