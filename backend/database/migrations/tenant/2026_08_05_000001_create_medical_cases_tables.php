<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_no')->unique();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('restrict');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->onDelete('set null');
            $table->foreignId('bonus_scheme_id')->nullable()->index();
            $table->foreignId('delivery_address_id')->nullable()->constrained('customer_addresses')->onDelete('set null');
            $table->foreignId('doctor_contact_id')->nullable()->constrained('customer_contacts')->onDelete('set null');
            $table->foreignId('requestor_contact_id')->nullable()->constrained('customer_contacts')->onDelete('set null');
            $table->foreignId('buyer_contact_id')->nullable()->constrained('customer_contacts')->onDelete('set null');
            $table->string('patient_reference')->nullable();
            $table->text('patient_name_encrypted')->nullable();
            $table->date('case_date')->nullable();
            $table->date('procedure_date')->nullable();
            $table->string('location')->nullable();
            $table->enum('status', ['draft', 'allocated', 'delivered', 'invoiced', 'completed', 'cancelled'])->default('draft')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('customer_id');
            $table->index('invoice_id');
            $table->index('case_date');
        });

        Schema::create('medical_case_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_case_id')->constrained()->onDelete('cascade');
            $table->foreignId('sales_order_item_id')->constrained('sales_order_lines')->onDelete('restrict');
            $table->foreignId('invoice_item_id')->nullable()->constrained('invoice_lines')->onDelete('set null');
            $table->foreignId('delivery_order_item_id')->nullable()->constrained('delivery_order_lines')->onDelete('set null');
            $table->foreignId('product_id')->constrained('products')->onDelete('restrict');
            $table->foreignId('lot_id')->nullable()->index();
            $table->decimal('allocated_qty', 15, 4)->default(0);
            $table->decimal('delivered_qty', 15, 4)->default(0);
            $table->decimal('invoiced_qty', 15, 4)->default(0);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->boolean('is_foc')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('medical_case_id');
            $table->index('product_id');
            $table->index('sales_order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_case_items');
        Schema::dropIfExists('medical_cases');
    }
};
