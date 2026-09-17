<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->string('case_number', 100)->nullable();
            $table->date('date_of_case')->nullable();
            $table->string('case_location')->nullable();
            $table->string('patient_name')->nullable();
            $table->string('cost_center_id', 100)->nullable();
            $table->date('delivery_date')->nullable();
            $table->string('pr_no', 100)->nullable();
            $table->string('contract_no', 100)->nullable();
            $table->string('plant_code', 100)->nullable();
            $table->string('company_code', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn([
                'case_number', 'date_of_case', 'case_location', 'patient_name',
                'cost_center_id', 'delivery_date', 'pr_no', 'contract_no',
                'plant_code', 'company_code',
            ]);
        });
    }
};
