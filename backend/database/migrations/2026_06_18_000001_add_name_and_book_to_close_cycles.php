<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add human-readable name and accounting book reference to close cycles.
     */
    public function up(): void
    {
        Schema::table('fco_close_cycles', function (Blueprint $table) {
            if (!Schema::hasColumn('fco_close_cycles', 'name')) {
                $table->string('name')->nullable()->after('period_id');
            }
            if (!Schema::hasColumn('fco_close_cycles', 'accounting_book_id')) {
                $table->unsignedBigInteger('accounting_book_id')->nullable()->after('entity_id');
                $table->index(['tenant_id', 'accounting_book_id']);
            }
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('fco_close_cycles', function (Blueprint $table) {
            if (Schema::hasColumn('fco_close_cycles', 'accounting_book_id')) {
                $table->dropIndex(['tenant_id', 'accounting_book_id']);
                $table->dropColumn('accounting_book_id');
            }
            if (Schema::hasColumn('fco_close_cycles', 'name')) {
                $table->dropColumn('name');
            }
        });
    }
};
