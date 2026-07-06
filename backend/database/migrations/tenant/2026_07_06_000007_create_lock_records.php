<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_lock_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('resource_type');
            $table->unsignedBigInteger('resource_id');
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamp('acquired_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'resource_type', 'resource_id']);
            $table->index(['tenant_id', 'resource_type', 'resource_id', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_lock_records');
    }
};
