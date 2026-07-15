<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->date('date_of_case')->nullable()->after('invoice_date');
            $table->string('case_location')->nullable()->after('date_of_case');
            $table->string('patient_name')->nullable()->after('case_location');
            $table->string('cost_center_id')->nullable()->after('patient_name');
            $table->date('delivery_date')->nullable()->after('cost_center_id');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'date_of_case',
                'case_location',
                'patient_name',
                'cost_center_id',
                'delivery_date',
            ]);
        });
    }
};
