<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_evidence_packs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name');
            $table->enum('type', ['implementation', 'test', 'deployment', 'acceptance']);
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'finalized'])->default('draft');
            $table->string('integrity_hash')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_evidence_packs');
    }
};
