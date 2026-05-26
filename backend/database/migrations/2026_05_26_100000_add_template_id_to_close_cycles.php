<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add template_id to fco_close_cycles to link a checklist template.
     */
    public function up(): void
    {
        Schema::table('fco_close_cycles', function (Blueprint $table) {
            $table->uuid('template_id')->nullable()->after('period_id');
            $table->index(['tenant_id', 'template_id']);
        });
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        Schema::table('fco_close_cycles', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'template_id']);
            $table->dropColumn('template_id');
        });
    }
};
