<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bill_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number')->unique(); // BP-2024-0001
            $table->foreignId('vendor_id')->constrained()->onDelete('restrict');
            $table->foreignId('bill_id')->nullable()->constrained()->onDelete('restrict');
            $table->date('payment_date');
            $table->decimal('amount', 15, 2);
            $table->enum('payment_method', ['cash', 'bank', 'card', 'check', 'other'])->default('bank');
            $table->string('reference')->nullable(); // Check #, Transaction ID
            $table->text('memo')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->onDelete('restrict');
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            $table->index('payment_number');
            $table->index('vendor_id');
            $table->index('bill_id');
            $table->index('payment_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bill_payments');
    }
};
