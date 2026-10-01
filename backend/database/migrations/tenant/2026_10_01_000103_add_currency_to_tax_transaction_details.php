<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-39 (#272): the tax trail records the document currency and the booking
 * exchange rate, so multi-currency tax bases can be reconstructed from the
 * trail without re-deriving them from the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_transaction_details', function (Blueprint $table) {
            $table->string('currency_code', 3)->nullable();
            $table->decimal('exchange_rate', 15, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tax_transaction_details', function (Blueprint $table) {
            $table->dropColumn(['currency_code', 'exchange_rate']);
        });
    }
};
