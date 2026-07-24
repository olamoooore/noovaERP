<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->uuid('billing_contact_id')->nullable()->after('attention_to');
            $table->uuid('shipping_contact_id')->nullable()->after('billing_contact_id');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn(['billing_contact_id', 'shipping_contact_id']);
        });
    }
};
