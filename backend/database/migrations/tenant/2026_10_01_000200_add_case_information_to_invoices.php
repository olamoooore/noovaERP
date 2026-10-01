<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot of ALL cases attached to an invoice (the single case_* columns
     * remain the primary/first case for PDF templates, exports and reports).
     * Each entry mirrors the invoice case fields plus an optional
     * medical_case_id when the row was picked from the case registry.
     */
    public function up(): void
    {
        if (Schema::hasColumn('invoices', 'case_information')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->json('case_information')->nullable()->after('case_number');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('invoices', 'case_information')) {
            Schema::table('invoices', function (Blueprint $table) {
                $table->dropColumn('case_information');
            });
        }
    }
};
