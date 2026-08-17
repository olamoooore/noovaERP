<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('features')) {
            return;
        }

        Schema::connection('pgsql')->create('features', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_core')->default(false);
            $table->decimal('price', 10, 2)->default(0);
            $table->timestamps();
        });

        // Seed the central features catalog
        DB::connection('pgsql')->table('features')->insert([
            ['key' => 'accounting', 'name' => 'Accounting', 'is_core' => true, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'communication', 'name' => 'Communication', 'is_core' => true, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'core', 'name' => 'Core', 'is_core' => true, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'event_management', 'name' => 'Event Management', 'is_core' => true, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'field-service', 'name' => 'Field Service', 'is_core' => true, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'inventory', 'name' => 'Inventory', 'is_core' => true, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'pos', 'name' => 'POS', 'is_core' => true, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'sales', 'name' => 'Sales', 'is_core' => true, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'website-builder', 'name' => 'Website Builder', 'is_core' => true, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'ai', 'name' => 'AI', 'is_core' => false, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'crm', 'name' => 'NoovaERP CRM', 'is_core' => false, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'ecommerce', 'name' => 'Website', 'is_core' => false, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'financial-reporting', 'name' => 'FinancialReporting', 'is_core' => false, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'iot', 'name' => 'NoovaERP IoT Hub', 'is_core' => false, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'loyalty', 'name' => 'Loyalty', 'is_core' => false, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'ocr-bookkeeping', 'name' => 'OCR Bookkeeping', 'is_core' => false, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'payment_gateway', 'name' => 'Payment Gateway', 'is_core' => false, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'template_builder', 'name' => 'Template Builder', 'is_core' => false, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'whatsapp', 'name' => 'WhatsApp', 'is_core' => false, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'hrm', 'name' => 'NoovaERP HRM', 'is_core' => false, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'fulfillment', 'name' => 'Fulfillment', 'is_core' => false, 'price' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('features');
    }
};
