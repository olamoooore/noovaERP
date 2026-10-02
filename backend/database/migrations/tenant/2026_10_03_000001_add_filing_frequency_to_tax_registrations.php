<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #748 TU2: the filing frequency a registration files at (SG monthly or
 * quarterly, MY two-monthly or monthly). Validated against the jurisdiction's
 * pack `period_shapes`; #750 derives the filing periods from this and the
 * cycle start month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_registrations', function (Blueprint $table) {
            if (! Schema::hasColumn('tax_registrations', 'filing_frequency')) {
                $table->string('filing_frequency', 20)->nullable()->after('filing_start_month');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tax_registrations', function (Blueprint $table) {
            $table->dropColumn('filing_frequency');
        });
    }
};
