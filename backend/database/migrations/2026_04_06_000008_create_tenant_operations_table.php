<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_operations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('operation'); // suspend, reinstate, ping_health, migrate_plan, run_seeder
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->enum('status', ['pending', 'success', 'failure'])->default('pending');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['operation', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_operations');
    }
};
