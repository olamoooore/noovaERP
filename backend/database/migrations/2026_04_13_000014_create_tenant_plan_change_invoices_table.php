<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_plan_change_invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('plan_change_id')
                ->constrained('tenant_plan_changes')
                ->cascadeOnDelete();

            $table->foreignId('invoice_id')
                ->constrained('subscription_invoices')
                ->restrictOnDelete();

            $table->enum('type', ['credit', 'charge']);

            // Always positive — type determines direction
            $table->integer('amount_cents');

            $table->timestamps();

            $table->index('plan_change_id');
            $table->unique(['plan_change_id', 'type']); // one credit + one charge per change
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_plan_change_invoices');
    }
};
