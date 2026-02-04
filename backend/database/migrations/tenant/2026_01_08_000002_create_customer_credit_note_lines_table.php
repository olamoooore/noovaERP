<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('customer_credit_notes')->onDelete('cascade');
            $table->foreignId('invoice_line_id')->nullable()->constrained('invoice_lines');
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->text('description');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->foreignId('account_id')->nullable()->constrained('chart_of_accounts');
            $table->timestamps();
            
            $table->index('credit_note_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_note_lines');
    }
};
