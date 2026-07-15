<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->string('salesperson')->nullable()->after('attention_to');
            $table->string('delivery_lead_time')->nullable()->after('salesperson');
            $table->string('shipping_method')->nullable()->after('delivery_lead_time');
            $table->string('shipping_terms')->nullable()->after('shipping_method');
            $table->string('validity')->nullable()->after('shipping_terms');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn([
                'salesperson',
                'delivery_lead_time',
                'shipping_method',
                'shipping_terms',
                'validity',
            ]);
        });
    }
};
