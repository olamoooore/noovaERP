<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_approval_escalations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->unsignedBigInteger('approval_request_id')->index();
            $table->unsignedBigInteger('escalated_from')->nullable();
            $table->string('escalation_role', 50);
            $table->timestamp('escalated_at');
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_approval_escalations');
    }
};
