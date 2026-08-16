<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('attention_to')->nullable()->after('customer_id');
            $table->string('contact_email')->nullable()->after('attention_to');
            $table->string('contact_phone')->nullable()->after('contact_email');
            $table->json('customer_name_snapshot')->nullable()->after('contact_phone');
            $table->json('billing_address_snapshot')->nullable()->after('customer_name_snapshot');
            $table->json('shipping_address_snapshot')->nullable()->after('billing_address_snapshot');
            $table->string('company_registration_number')->nullable()->after('shipping_address_snapshot');
            $table->string('tax_registration_number')->nullable()->after('company_registration_number');
        });
    }

    public function down(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropColumn([
                'attention_to',
                'contact_email',
                'contact_phone',
                'customer_name_snapshot',
                'billing_address_snapshot',
                'shipping_address_snapshot',
                'company_registration_number',
                'tax_registration_number',
            ]);
        });
    }
};
