<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `currency` to the payments table for the customer-invoice payment-import
 * feature. The Payment model already lists `currency` in $fillable but the
 * underlying column was never added.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments') || Schema::hasColumn('payments', 'currency')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->string('currency', 8)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'currency')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('currency');
            });
        }
    }
};
