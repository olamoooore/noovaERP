<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auditor_id')->constrained('auditors')->onDelete('cascade');
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->string('source_type', 100); // report_line, transaction, document, account
            $table->string('source_id', 255);
            $table->json('source_context')->nullable(); // { report_name, line_item, amount, period }
            $table->string('title');
            $table->text('description');
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('medium');
            $table->enum('status', ['open', 'responded', 'resolved', 'rejected', 'closed'])->default('open');
            $table->date('due_date')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('closure_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['auditor_id', 'status']);
            $table->index(['tenant_id', 'status']);
            $table->index(['source_type', 'source_id']);
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_queries');
    }
};
