<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assurance_blocking_policies', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->index();

            $table->string('policy_version', 50)->default('1.0');
            $table->string('policy_name', 255)->nullable();

            $table->string('finding_category', 50)->index();
            $table->string('assertion_level', 50)->nullable();
            $table->string('severity_threshold', 20)->default('HIGH');
            $table->string('risk_threshold', 20)->nullable();

            $table->json('action_scope')->nullable();
            $table->string('block_mode', 30)->default('BLOCK');

            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('status', 20)->default('active');

            $table->timestamps();

            $table->index(['tenant_id', 'finding_category', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assurance_blocking_policies');
    }
};
