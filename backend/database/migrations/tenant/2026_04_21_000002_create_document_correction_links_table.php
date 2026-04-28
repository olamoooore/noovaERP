<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_correction_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id'); // tenant context — no FK to central tenants table
            $table->string('document_type');
            $table->unsignedBigInteger('document_id');
            $table->string('related_document_type');
            $table->unsignedBigInteger('related_document_id');
            $table->enum('relation_type', [
                'reversal_of',
                'replaced_by',
                'replacement_for',
                'credited_by',
                'credit_note_for',
                'refund_for',
                'payment_unapplied_by',
                'superseded_by',
            ]);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason_code')->nullable();
            $table->text('reason_text')->nullable();
            $table->uuid('correction_group_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'document_type', 'document_id']);
            $table->index(['tenant_id', 'related_document_type', 'related_document_id']);
            $table->index(['tenant_id', 'relation_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_correction_links');
    }
};