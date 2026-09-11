<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statement_transactions', function (Blueprint $table) {
            $table->string('customer_code')->nullable()->after('customer_reference');
        });
        Schema::table('bank_statement_matches', function (Blueprint $table) {
            $table->text('review_reason')->nullable()->after('match_reasons');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_matches', fn (Blueprint $table) => $table->dropColumn('review_reason'));
        Schema::table('bank_statement_transactions', fn (Blueprint $table) => $table->dropColumn('customer_code'));
    }
};
