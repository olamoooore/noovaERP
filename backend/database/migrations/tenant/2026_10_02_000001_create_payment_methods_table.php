<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #486: tenant-configurable payment-method master.
 *
 * Validation (manual entry), posting (method → mapping key → GL) and the UI
 * all read from this table instead of three divergent hardcoded lists. The
 * generic rows are seeded by PaymentMethodsSeeder; country-localisation packs
 * (MY/SG) contribute their rails (DuitNow, FPX, PayNow, GrabPay) on top.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('label');
            $table->string('mapping_key')->nullable();
            $table->foreignId('default_bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->boolean('requires_reference')->default(false);
            $table->boolean('requires_cheque_fields')->default(false);
            $table->string('country_code', 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
