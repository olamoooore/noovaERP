<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('credit_note_number', 50)->unique();
            $table->foreignId('vendor_id')->constrained('vendors');
            $table->foreignId('bill_id')->constrained('bills');
            $table->date('credit_note_date');
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('draft');
            $table->decimal('subtotal', 15, 2);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2);
            $table->string('currency_code', 3)->default('USD');
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('posted_by')->nullable()->constrained('users');
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->foreign('currency_code')->references('code')->on('currencies');

            $table->index('vendor_id');
            $table->index('bill_id');
            $table->index('status');
            $table->index('credit_note_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_credit_notes');
    }
};
