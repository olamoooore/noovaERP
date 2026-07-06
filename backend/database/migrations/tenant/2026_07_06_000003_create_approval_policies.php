<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_approval_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('request_type', 50)->index();
            $table->decimal('threshold_amount', 15, 2)->default(0);
            $table->unsignedInteger('required_approvers')->default(1);
            $table->string('escalation_role', 50)->nullable();
            $table->unsignedInteger('escalation_hours')->default(24);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'request_type', 'threshold_amount']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_approval_policies');
    }
};
