<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'case_number')) {
                $table->string('case_number', 100)->nullable()->after('due_date');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasColumn('invoices', 'case_number')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('case_number');
        });
    }
};
