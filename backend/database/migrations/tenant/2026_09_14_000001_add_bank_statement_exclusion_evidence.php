<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_transactions', function (Blueprint $table) {
            $table->string('exclusion_type', 32)->nullable()->after('exclusion_reason');
            $table->jsonb('exclusion_evidence')->nullable()->after('exclusion_type');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_transactions', fn (Blueprint $table) => $table->dropColumn(['exclusion_type', 'exclusion_evidence']));
    }
};
