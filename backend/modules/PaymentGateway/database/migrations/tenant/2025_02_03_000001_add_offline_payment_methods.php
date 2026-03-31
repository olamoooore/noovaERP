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
        // Add offline payment methods to payment_gateways table
        // This allows configuring Cash, Bank Transfer, etc. as payment methods
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->enum('gateway_type', ['online', 'offline'])->default('online')->after('gateway_name');
            $table->json('offline_config')->nullable()->after('config');
        });

        // Create offline_payment_methods table for detailed bank/account info
        Schema::create('offline_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_gateway_id')->constrained()->onDelete('cascade');
            $table->string('method_name'); // e.g., "Bank Transfer - Maybank"
            $table->string('method_code'); // e.g., "bank_transfer_maybank"
            $table->text('instructions')->nullable(); // Payment instructions for customer
            $table->json('account_details')->nullable(); // Bank name, account number, etc.
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offline_payment_methods');
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->dropColumn(['gateway_type', 'offline_config']);
        });
    }
};
