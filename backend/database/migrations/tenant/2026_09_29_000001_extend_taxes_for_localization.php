<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->string('jurisdiction', 2)->nullable()->after('code');
            $table->string('regime', 30)->nullable()->after('jurisdiction');
            $table->string('scope', 20)->default('both')->after('regime');
            $table->string('treatment', 30)->default('standard_rated')->after('scope');
            $table->date('effective_from')->nullable()->after('is_inclusive');
            $table->date('effective_to')->nullable()->after('effective_from');

            $table->index(['jurisdiction', 'regime', 'scope', 'is_active'], 'taxes_resolution_idx');
            $table->index(['effective_from', 'effective_to'], 'taxes_validity_idx');
        });
    }

    public function down(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->dropIndex('taxes_resolution_idx');
            $table->dropIndex('taxes_validity_idx');
            $table->dropColumn([
                'jurisdiction', 'regime', 'scope', 'treatment',
                'effective_from', 'effective_to',
            ]);
        });
    }
};
