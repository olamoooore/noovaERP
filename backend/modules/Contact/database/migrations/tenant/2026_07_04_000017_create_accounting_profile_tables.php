<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_customer_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->index();
            $table->string('customer_code')->nullable();
            $table->string('payment_terms', 50)->default('net_30');
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('price_list')->nullable();
            $table->string('discount_group')->nullable();
            $table->string('payment_method')->nullable();
            $table->uuid('default_bank_account_id')->nullable();
            $table->date('account_opened_date')->nullable();
            $table->string('account_status', 50)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'customer_code']);
            $table->index(['tenant_id', 'account_status']);
        });

        Schema::create('accounting_vendor_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('contact_id')->index();
            $table->string('vendor_code')->nullable();
            $table->string('payment_terms', 50)->default('net_30');
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('payment_method')->nullable();
            $table->uuid('default_bank_account_id')->nullable();
            $table->date('account_opened_date')->nullable();
            $table->string('account_status', 50)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'vendor_code']);
            $table->index(['tenant_id', 'account_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_vendor_profiles');
        Schema::dropIfExists('accounting_customer_profiles');
    }
};
