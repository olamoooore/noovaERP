<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('close_dependency_definitions', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();

            $table->string('dependency_code', 100)->index();
            $table->string('dependency_name', 255)->nullable();
            $table->string('source_capability', 50)->index();
            $table->string('source_readiness_contract', 255)->nullable();

            $table->integer('sequence')->default(0);
            $table->string('dependency_mode', 30)->default('REQUIRED');
            $table->string('blocking_policy', 30)->default('BLOCKING');

            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('active');

            $table->timestamps();

            $table->index(['tenant_id', 'dependency_mode', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('close_dependency_definitions');
    }
};
