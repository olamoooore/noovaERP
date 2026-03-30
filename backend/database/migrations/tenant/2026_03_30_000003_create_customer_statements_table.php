<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('period_type', 20)->default('custom');
            $table->boolean('include_paid')->default(false);
            $table->boolean('email_sent')->default(false);
            $table->timestamp('email_sent_at')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('pdf_path')->nullable();
            $table->json('payload');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_statements');
    }
};
