<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-13 follow-up: a few tenants own a schema-local copy of
 * tax_determination_results (created before the table moved to the central
 * schema). The central migration covers public; this one covers those copies
 * so invoice posting never fails on a missing `determined_by` column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tax_determination_results')) {
            return;
        }

        if (! Schema::hasColumn('tax_determination_results', 'determined_by')) {
            Schema::table('tax_determination_results', function (Blueprint $table) {
                $table->uuid('determined_by')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tax_determination_results')) {
            return;
        }

        if (Schema::hasColumn('tax_determination_results', 'determined_by')) {
            Schema::table('tax_determination_results', function (Blueprint $table) {
                $table->dropIndex(['determined_by']);
                $table->dropColumn('determined_by');
            });
        }
    }
};