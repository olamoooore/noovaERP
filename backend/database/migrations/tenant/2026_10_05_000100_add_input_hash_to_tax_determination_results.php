<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-04/G-05 follow-up: a few tenants own a schema-local copy of
 * tax_determination_results (created before the table moved to the central
 * schema). The central idempotency migration covers public; this one covers
 * those copies so invoice posting never fails on a missing `input_hash`
 * column (observed: posting 500ed after the invoice was already written).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tax_determination_results')) {
            return;
        }

        if (! Schema::hasColumn('tax_determination_results', 'input_hash')) {
            Schema::table('tax_determination_results', function (Blueprint $table) {
                $table->string('input_hash', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tax_determination_results')) {
            return;
        }

        if (Schema::hasColumn('tax_determination_results', 'input_hash')) {
            Schema::table('tax_determination_results', function (Blueprint $table) {
                $table->dropColumn('input_hash');
            });
        }
    }
};
