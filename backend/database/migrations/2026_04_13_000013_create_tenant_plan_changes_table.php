<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_plan_changes', function (Blueprint $table) {
            $table->id();

            $table->uuid('tenant_id');
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->foreignId('from_family_id')
                ->nullable()
                ->constrained('plan_families')
                ->nullOnDelete();

            $table->foreignId('to_family_id')
                ->constrained('plan_families')
                ->restrictOnDelete();

            $table->date('effective_date');

            $table->enum('status', ['pending', 'applied', 'cancelled'])
                ->default('pending');

            // Proration amounts — stored in cents (signed integer)
            $table->integer('proration_credit_cents')->default(0);
            $table->integer('proration_charge_cents')->default(0);

            $table->text('reason')->nullable();

            $table->timestamp('applied_at')->nullable();

            $table->foreignId('applied_by_admin_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('created_by_admin_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'effective_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_plan_changes');
    }
};
