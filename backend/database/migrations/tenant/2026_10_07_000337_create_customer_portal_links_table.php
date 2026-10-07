<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #337 — customer portal access links.
 *
 * One row per signed, expiring, revocable portal link. A link grants an
 * anonymous holder read access to exactly ONE customer's posted invoices,
 * sent/accepted quotations and statement (optionally narrowed to the legal
 * entity it was issued for). Only the SHA-256 hash of the token is stored —
 * the raw token is shown to staff once, at issuance; the central
 * `public.document_token_lookup` row (#466) carries the same hash.
 *
 * Additive only: a new table, no existing data touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_portal_links')) {
            return;
        }

        Schema::create('customer_portal_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('legal_entity_id')->nullable()->index();
            $table->string('token_hash', 64)->unique();
            $table->string('label', 120)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamp('last_accessed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_portal_links');
    }
};
