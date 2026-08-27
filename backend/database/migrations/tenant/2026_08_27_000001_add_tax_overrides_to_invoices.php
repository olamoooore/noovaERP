<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('tax_jurisdiction', 10)->nullable()->after('tax_registration_number');
            $table->string('tax_regime', 30)->nullable()->after('tax_jurisdiction');
            $table->string('tax_treatment', 40)->nullable()->after('tax_regime');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['tax_jurisdiction', 'tax_regime', 'tax_treatment']);
        });
    }
};
