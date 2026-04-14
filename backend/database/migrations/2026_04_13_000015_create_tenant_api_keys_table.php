<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_api_keys', function (Blueprint $table) {
            $table->id();

            $table->uuid('tenant_id');
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            // Human-readable label (e.g. "Production ERP", "Staging")
            $table->string('name');

            // SHA-256 hash of the raw key — raw key shown only once on creation
            $table->string('key_hash', 64)->unique();

            // First 8 chars of the raw key for identification (e.g. "ten_abc1…")
            $table->string('key_prefix', 16);

            $table->boolean('is_active')->default(true);

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->foreignId('created_by_admin_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_api_keys');
    }
};
