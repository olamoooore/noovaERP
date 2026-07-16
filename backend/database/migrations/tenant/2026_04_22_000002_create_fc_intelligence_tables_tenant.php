<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-scoped Financial Close intelligence tables.
 * No FK to public.tenants — runs inside each tenant's PostgreSQL schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fci_ai_findings')) {
            Schema::create('fci_ai_findings', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('tenant_id');
                $table->uuid('review_id');
                $table->string('entity_id');
                $table->string('account_code')->nullable();
                $table->string('finding_type');
                $table->string('status')->default('open');
                $table->string('risk_status')->default('normal');
                $table->float('confidence')->default(0);
                $table->text('description');
                $table->json('structured_output')->nullable();
                $table->string('accepted_by')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->string('dismissed_by')->nullable();
                $table->timestamp('dismissed_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'entity_id', 'account_code']);
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('fci_ai_feedback')) {
            Schema::create('fci_ai_feedback', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('tenant_id');
                $table->uuid('finding_id');
                $table->string('user_id');
                $table->string('action');
                $table->text('comment')->nullable();
                $table->json('edited_output')->nullable();
                $table->timestamps();

                $table->foreign('finding_id')->references('id')->on('fci_ai_findings')->onDelete('cascade');
                $table->index(['tenant_id', 'finding_id']);
            });
        }

        if (! Schema::hasTable('fci_close_readiness_snapshots')) {
            Schema::create('fci_close_readiness_snapshots', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('tenant_id');
                $table->string('entity_id');
                $table->string('period_id');
                $table->float('readiness_score')->default(0);
                $table->integer('total_accounts')->default(0);
                $table->integer('reviewed_accounts')->default(0);
                $table->integer('signed_off_accounts')->default(0);
                $table->integer('open_blockers')->default(0);
                $table->integer('open_findings')->default(0);
                $table->json('section_breakdown')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('generated_at')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'entity_id', 'period_id']);
                $table->index(['tenant_id', 'generated_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fci_close_readiness_snapshots');
        Schema::dropIfExists('fci_ai_feedback');
        Schema::dropIfExists('fci_ai_findings');
    }
};
