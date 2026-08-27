<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->index();
            $table->unsignedBigInteger('target_user_id')->index();
            $table->string('tenant_id', 100)->index();
            $table->string('token', 64)->unique();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('expires_at');
            $table->timestamp('ended_at')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('status', 20)->default('active')->index();

            $table->index(['actor_id', 'status']);
            $table->index(['target_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonations');
    }
};
