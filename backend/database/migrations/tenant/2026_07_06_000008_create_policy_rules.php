<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_policy_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('entity_type');
            $table->string('action');
            $table->json('rules');
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'entity_type', 'action']);
            $table->index(['tenant_id', 'entity_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_policy_rules');
    }
};
