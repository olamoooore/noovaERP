<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auditor_id')->constrained('auditors')->onDelete('cascade');
            $table->foreignId('tenant_id')->constrained()->onDelete('cascade');
            $table->json('scope'); // { entity_id, period, modules, reports }
            $table->string('file_name');
            $table->string('file_path', 500);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('file_type', 50)->default('application/zip');
            $table->timestamp('downloaded_at')->nullable();
            $table->string('download_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['auditor_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_exports');
    }
};
