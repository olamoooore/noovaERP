<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #746 T13: rules name their successor. When a live rule is replaced through
 * the versioning path (manual edit or country-pack upgrade), the closed row
 * points at the replacement so a product still pinned to the old rule can
 * follow it — with the same treatment — instead of silently falling through
 * to a default/newest rule that may charge something else entirely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            if (! Schema::hasColumn('taxes', 'superseded_by_id')) {
                $table->unsignedBigInteger('superseded_by_id')->nullable()->after('pack_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->dropColumn('superseded_by_id');
        });
    }
};
