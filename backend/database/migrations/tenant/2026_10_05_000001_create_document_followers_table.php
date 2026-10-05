<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #639 (CHATTER-EPIC #628) — per-document followers.
 *
 * Odoo-style follower list for a document: internal users (with per-subtype
 * subscription flags) and partner contacts (customer contacts) who receive
 * customer-facing messages through the Communication engine.
 *
 * Additive only — new table, no existing data touched. Follower rows are
 * tenant-scoped by the tenant schema itself (like chatter_messages and
 * communication_messages); the tenant_id column is kept for parity with those
 * tables and to scope the mirrored communication_subscribers row.
 *
 * The two unique indexes are intentionally partial-by-NULL: a follower row is
 * either internal (user_id set, contact_id NULL) or a partner (contact_id set,
 * user_id NULL), and PostgreSQL permits multiple NULLs in a unique constraint,
 * so `updateOrCreate`-style idempotent following works for both kinds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_followers', function (Blueprint $table) {
            $table->id();
            // `string`, not `uuid`: chatter_messages / communication_subscribers
            // use string tenant ids, and test/non-UUID tenant keys exist in the
            // platform's tenant-bound suites.
            $table->string('tenant_id', 100)->nullable()->index();

            $table->string('document_type', 191);
            $table->unsignedBigInteger('document_id');

            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('contact_id')->nullable();

            // internal = tenant user, partner = customer contact.
            $table->string('follower_type', 20)->default('internal');

            $table->boolean('subscribe_messages')->default(true);
            $table->boolean('subscribe_activities')->default(true);
            $table->boolean('subscribe_stage_changes')->default(true);

            // manual | auto:creator | auto:salesperson | auto:approver | auto:stage_change
            $table->string('follow_source', 60)->default('manual');

            $table->timestamps();

            $table->index(['document_type', 'document_id'], 'document_followers_document_idx');
            $table->unique(['document_type', 'document_id', 'user_id'], 'document_followers_user_unique');
            $table->unique(['document_type', 'document_id', 'contact_id'], 'document_followers_contact_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_followers');
    }
};
