<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditor_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auditor_id')->constrained('auditors')->onDelete('cascade');
            $table->string('tenant_id', 50)->index();
            $table->string('action', 100); // login, view_report, view_transaction, view_document, export, query_created, query_responded, query_closed
            $table->string('resource_type', 100)->nullable(); // report, transaction, document, query, export
            $table->string('resource_id', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['auditor_id', 'action']);
            $table->index(['auditor_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditor_activity_logs');
    }
};
