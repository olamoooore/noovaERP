<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * storefront_account_addresses — shopper address book (FR-DASH-03).
 * Field names intentionally mirror checkout payload keys so the same JSON
 * round-trips between the dashboard, the future checkout and the order
 * address snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_account_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('storefront_accounts')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('company', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address1', 255);
            $table->string('address2', 255)->nullable();
            $table->string('city', 120);
            $table->string('state', 120)->nullable();
            $table->string('postal_code', 20);
            $table->string('country_code', 2);
            $table->boolean('is_default_billing')->default(false);
            $table->boolean('is_default_shipping')->default(false);
            $table->timestamps();

            $table->index(['account_id', 'is_default_shipping']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_account_addresses');
    }
};
