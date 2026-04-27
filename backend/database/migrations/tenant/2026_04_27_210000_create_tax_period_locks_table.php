<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_period_locks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('period', 7); // YYYY-MM
            $table->timestamp('closed_at');
            $table->unsignedBigInteger('closed_by')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'period']);
            $table->index(['tenant_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_period_locks');
    }
};
