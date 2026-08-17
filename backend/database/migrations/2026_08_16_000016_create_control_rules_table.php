<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();

            $table->string('rule_code', 100)->index();
            $table->string('rule_version', 50)->default('1.0');
            $table->string('rule_type', 50)->index();
            $table->string('rule_name', 255)->nullable();
            $table->text('description')->nullable();

            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->json('applicable_scope')->nullable();
            $table->string('severity_policy', 50)->nullable();
            $table->string('blocking_policy', 50)->default('WARNING');
            $table->unsignedBigInteger('supersedes_rule_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'rule_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_rules');
    }
};
