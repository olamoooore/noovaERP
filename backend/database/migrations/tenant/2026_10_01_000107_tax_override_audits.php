<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #277 F5: every manual tax override is recorded with engine vs applied
 * values, a mandatory reason and (for high-risk transitions) an independent
 * approver. Feeds the override report (F15) and assurance flags (F18).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_override_audits', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 50)->index();
            $table->unsignedBigInteger('document_id')->index();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('reason_code', 50);
            $table->string('reason_text', 255)->nullable();
            $table->json('engine_values');
            $table->json('applied_values');
            $table->decimal('delta_tax_amount', 15, 2)->default(0);
            $table->boolean('high_risk')->default(false);
            $table->unsignedBigInteger('approver_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['document_type', 'document_id']);
            $table->index(['reason_code', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_override_audits');
    }
};
