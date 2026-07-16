<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_debit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debit_note_id')->constrained('vendor_debit_notes')->onDelete('cascade');
            $table->foreignId('bill_line_id')->nullable()->constrained('bill_lines');
            $table->foreignId('product_id')->nullable()->constrained('products');
            $table->text('description');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 15, 2);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->foreignId('account_id')->nullable()->constrained('chart_of_accounts');
            $table->timestamps();

            $table->index('debit_note_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_debit_note_lines');
    }
};
