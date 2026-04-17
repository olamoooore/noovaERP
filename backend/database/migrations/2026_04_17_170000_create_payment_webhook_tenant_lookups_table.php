<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_tenant_lookups', function (Blueprint $table) {
            $table->id();
            $table->string('gateway_name');
            $table->string('transaction_id');
            $table->uuid('tenant_id');
            $table->timestamps();

            $table->unique(['gateway_name', 'transaction_id'], 'payment_webhook_tenant_lookup_unique');
            $table->index('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_tenant_lookups');
    }
};
