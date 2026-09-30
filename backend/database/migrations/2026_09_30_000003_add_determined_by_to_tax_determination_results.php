<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-13: determination rows must carry actor lineage. `determined_by` stores
 * the acting user mapped to a deterministic uuid (same convention as
 * authority_evaluation_snapshots.actor_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_determination_results', function (Blueprint $table) {
            $table->uuid('determined_by')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('tax_determination_results', function (Blueprint $table) {
            $table->dropIndex(['determined_by']);
            $table->dropColumn('determined_by');
        });
    }
};
