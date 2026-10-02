<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #739 T6: pack provenance on tenant tax rules.
 *
 * `pack_version` records which version of the country pack the row came from,
 * and `pack_snapshot` records the field values the pack supplied at that
 * moment. Together they make drift detectable: a difference between the
 * snapshot and the row is a TENANT edit (never to be overwritten silently),
 * and a difference between the snapshot and the current pack file is a PACK
 * change that an explicit upgrade applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            if (! Schema::hasColumn('taxes', 'pack_version')) {
                $table->unsignedInteger('pack_version')->nullable()->after('version');
            }

            if (! Schema::hasColumn('taxes', 'pack_snapshot')) {
                $table->json('pack_snapshot')->nullable()->after('pack_version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->dropColumn(['pack_version', 'pack_snapshot']);
        });
    }
};
