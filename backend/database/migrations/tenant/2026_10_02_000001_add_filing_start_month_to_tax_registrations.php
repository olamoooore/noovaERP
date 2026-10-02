<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #741 T8: MY SST-02 taxable periods are two months long, aligned to each
 * registrant's own cycle — the start month of that cycle is registration
 * data, not a global constant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_registrations', function (Blueprint $table) {
            if (! Schema::hasColumn('tax_registrations', 'filing_start_month')) {
                $table->unsignedTinyInteger('filing_start_month')->default(1)->after('status')
                    ->comment('First month of the registrant filing cycle (1-12)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tax_registrations', function (Blueprint $table) {
            if (Schema::hasColumn('tax_registrations', 'filing_start_month')) {
                $table->dropColumn('filing_start_month');
            }
        });
    }
};
