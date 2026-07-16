<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fci_account_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('entity_id');
            $table->string('book_id');
            $table->string('account_code');
            $table->string('account_name');
            $table->string('status')->default('not_started');
            $table->string('risk_status')->default('normal');
            $table->string('assigned_to')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'entity_id', 'book_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('fci_account_rollforwards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('review_id');
            $table->string('entity_id');
            $table->string('account_code');
            $table->string('period_id');
            $table->decimal('opening_balance', 15, 2)->default(0);
            $table->decimal('movements', 15, 2)->default(0);
            $table->decimal('closing_balance', 15, 2)->default(0);
            $table->json('movement_breakdown')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('review_id')->references('id')->on('fci_account_reviews')->onDelete('cascade');
            $table->index(['tenant_id', 'entity_id', 'account_code', 'period_id']);
        });

        Schema::create('fci_supporting_evidence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('review_id');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('status')->default('attached');
            $table->string('uploaded_by');
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('review_id')->references('id')->on('fci_account_reviews')->onDelete('cascade');
            $table->index(['tenant_id', 'review_id']);
        });

        Schema::create('fci_review_comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('review_id');
            $table->string('author_id');
            $table->text('body');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('review_id')->references('id')->on('fci_account_reviews')->onDelete('cascade');
            $table->index(['tenant_id', 'review_id']);
        });

        Schema::create('fci_signoffs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('review_id');
            $table->string('signed_by');
            $table->string('role');
            $table->string('status');
            $table->text('notes')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('review_id')->references('id')->on('fci_account_reviews')->onDelete('cascade');
            $table->index(['tenant_id', 'review_id']);
        });

        Schema::create('fci_unreconciled_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('review_id');
            $table->string('entity_id');
            $table->string('account_code');
            $table->decimal('amount', 15, 2);
            $table->string('currency')->default('USD');
            $table->string('status')->default('open');
            $table->text('description')->nullable();
            $table->integer('aging_days')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('review_id')->references('id')->on('fci_account_reviews')->onDelete('cascade');
            $table->index(['tenant_id', 'entity_id', 'account_code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('fci_subledger_tieouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('review_id');
            $table->string('entity_id');
            $table->string('account_code');
            $table->string('subledger_type');
            $table->decimal('gl_balance', 15, 2)->default(0);
            $table->decimal('subledger_balance', 15, 2)->default(0);
            $table->decimal('variance', 15, 2)->default(0);
            $table->string('status')->default('not_tied');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('review_id')->references('id')->on('fci_account_reviews')->onDelete('cascade');
            $table->index(['tenant_id', 'entity_id', 'account_code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('fci_evidence_packs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('entity_id');
            $table->string('period_id');
            $table->string('status')->default('pending');
            $table->string('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'entity_id', 'period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fci_evidence_packs');
        Schema::dropIfExists('fci_subledger_tieouts');
        Schema::dropIfExists('fci_unreconciled_items');
        Schema::dropIfExists('fci_signoffs');
        Schema::dropIfExists('fci_review_comments');
        Schema::dropIfExists('fci_supporting_evidence');
        Schema::dropIfExists('fci_account_rollforwards');
        Schema::dropIfExists('fci_account_reviews');
    }
};
