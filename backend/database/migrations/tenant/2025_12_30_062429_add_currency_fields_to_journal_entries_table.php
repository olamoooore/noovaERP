<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            // Add multi-currency support columns
            $table->string('original_currency_code', 3)->nullable()->after('description');
            $table->decimal('original_total_amount', 15, 2)->nullable()->after('original_currency_code');
            $table->decimal('exchange_rate', 15, 6)->nullable()->after('original_total_amount');
            $table->decimal('exchange_gain_loss', 15, 2)->nullable()->after('exchange_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropColumn([
                'original_currency_code',
                'original_total_amount',
                'exchange_rate',
                'exchange_gain_loss',
            ]);
        });
    }
};
