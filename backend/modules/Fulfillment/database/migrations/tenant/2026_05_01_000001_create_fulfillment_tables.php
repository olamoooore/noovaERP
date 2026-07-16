<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fulfillment_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('carrier_code', 50);
            $table->string('name', 255);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->json('config')->nullable();
            $table->json('settings')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'carrier_code'], 'unique_tenant_carrier');
        });

        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('provider_id');
            $table->string('name', 255);
            $table->string('service_code', 100);
            $table->text('description')->nullable();
            $table->json('transit_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('base_rate', 10, 2)->default(0);
            $table->decimal('rate_per_kg', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'service_code'], 'unique_tenant_service');
        });

        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();
            $table->string('name', 255);
            $table->string('country_codes', 500);
            $table->json('postal_code_patterns')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shipping_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();
            $table->string('name', 255);
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('conditions')->nullable();
            $table->json('actions')->nullable();
            $table->timestamps();
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('shipment_number', 50)->unique();
            $table->uuid('provider_id');
            $table->string('order_id')->nullable()->index();
            $table->string('order_type', 50)->nullable();
            $table->string('status', 50)->default('draft')->index();
            $table->string('service_type', 100)->nullable();
            $table->string('tracking_number', 100)->nullable()->index();
            $table->string('tracking_url', 500)->nullable();
            $table->string('label_url', 500)->nullable();
            $table->string('label_format', 20)->default('pdf');
            $table->date('ship_date')->nullable();
            $table->date('estimated_delivery')->nullable();
            $table->timestamp('actual_delivery')->nullable();
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->string('currency_code', 3)->default('MYR');
            $table->decimal('weight', 10, 2)->default(0);
            $table->string('weight_unit', 10)->default('kg');
            $table->json('dimensions')->nullable();
            $table->json('shipper')->nullable();
            $table->json('recipient')->nullable();
            $table->boolean('signature_required')->default(false);
            $table->boolean('saturday_delivery')->default(false);
            $table->boolean('insurance_enabled')->default(false);
            $table->decimal('insurance_value', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index(['order_id', 'order_type']);
        });

        Schema::create('shipment_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shipment_id');
            $table->string('order_line_id')->nullable();
            $table->uuid('product_id')->nullable();
            $table->string('sku', 100)->nullable();
            $table->string('name', 255)->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('weight', 10, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('tracking_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shipment_id');
            $table->string('status', 50);
            $table->string('status_code', 50)->nullable();
            $table->text('description')->nullable();
            $table->string('location', 255)->nullable();
            $table->timestamp('timestamp')->nullable();
            $table->string('signed_by', 255)->nullable();
            $table->timestamps();

            $table->index(['shipment_id', 'timestamp']);
        });

        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('provider_id');
            $table->uuid('shipping_method_id')->nullable();
            $table->string('service_code', 100);
            $table->decimal('base_rate', 10, 2)->default(0);
            $table->decimal('rate_per_kg', 10, 2)->default(0);
            $table->decimal('flat_rate', 10, 2)->nullable();
            $table->string('destination_type', 50)->default('country');
            $table->string('destination_value', 255)->nullable();
            $table->decimal('min_weight', 10, 2)->nullable();
            $table->decimal('max_weight', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['provider_id', 'service_code']);
            $table->foreign('provider_id')->references('id')->on('fulfillment_providers')->onDelete('cascade');
            $table->foreign('shipping_method_id')->references('id')->on('shipping_methods')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_rates');
        Schema::dropIfExists('tracking_events');
        Schema::dropIfExists('shipment_lines');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('shipping_rules');
        Schema::dropIfExists('shipping_zones');
        Schema::dropIfExists('shipping_methods');
        Schema::dropIfExists('fulfillment_providers');
    }
};
