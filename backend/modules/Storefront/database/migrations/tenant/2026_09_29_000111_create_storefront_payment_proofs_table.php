<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * storefront_payment_proofs — PAY-R13 proof-of-payment artefacts for offline
 * settlement (bank transfer receipts, etc.). NOT the source of truth for
 * paid-ness: approval drives the canonical completion chain (same as a
 * gateway webhook); this table is the submitted evidence + review audit.
 * Rejects return the order to pending and allow re-submission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('storefront_orders')->cascadeOnDelete();
            $table->string('method', 40);
            $table->string('reference', 191)->nullable();
            $table->string('file_path', 500);
            $table->string('file_name', 191);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->string('status', 20)->default('submitted');
            $table->text('review_note')->nullable();
            $table->string('reviewed_by', 64)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_payment_proofs');
    }
};
