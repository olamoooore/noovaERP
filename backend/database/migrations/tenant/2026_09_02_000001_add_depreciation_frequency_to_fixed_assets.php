<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add depreciation_frequency to fixed_assets so the schedule
     * generator can know how many periods to emit (monthly, annually,
     * weekly, daily). Defaults to 'monthly' for backward compatibility.
     */
    public function up(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->string('depreciation_frequency', 20)
                ->default('monthly')
                ->after('depreciation_method');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropColumn('depreciation_frequency');
        });
    }
};
