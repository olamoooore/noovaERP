<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #912 [SALES-PLAT] P1: tamper-evident hash chain over the two #895
 * audit stores.
 *
 * - `platform_system_logs` (observer / domain-event rows) and `audit_logs`
 *   (explicit Sales lifecycle rows) get nullable `previous_hash`, `entry_hash`
 *   and `chain_sequence` columns. Nullable keeps the migration additive and
 *   backfill-safe: legacy rows stay untampered with a null hash and are
 *   skipped by verification.
 * - `audit_chain_heads` tracks the current chain head (sequence + hash) per
 *   tenant schema; the append path locks it FOR UPDATE so concurrent writers
 *   cannot fork the chain.
 * - `audit_chain_anchors` stores the HMAC-signed digest of the chain head
 *   captured by `audit:anchor` (periodic evidence that the chain existed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_system_logs', function (Blueprint $table) {
            $table->string('previous_hash', 64)->nullable()->after('correlation_id');
            $table->string('entry_hash', 64)->nullable()->after('previous_hash');
            $table->unsignedBigInteger('chain_sequence')->nullable()->after('entry_hash');

            $table->index(['tenant_id', 'chain_sequence'], 'platform_system_logs_chain_idx');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('previous_hash', 64)->nullable()->after('user_agent');
            $table->string('entry_hash', 64)->nullable()->after('previous_hash');
            $table->unsignedBigInteger('chain_sequence')->nullable()->after('entry_hash');

            $table->index('chain_sequence', 'audit_logs_chain_idx');
        });

        Schema::create('audit_chain_heads', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('chain_sequence')->default(0);
            $table->string('head_hash', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('audit_chain_anchors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('chain_sequence');
            $table->string('head_hash', 64);
            $table->string('period', 32);
            $table->string('digest', 64);
            $table->timestamp('anchored_at');
            $table->timestamps();

            $table->index(['tenant_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_anchors');
        Schema::dropIfExists('audit_chain_heads');

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_chain_idx');
            $table->dropColumn(['previous_hash', 'entry_hash', 'chain_sequence']);
        });

        Schema::table('platform_system_logs', function (Blueprint $table) {
            $table->dropIndex('platform_system_logs_chain_idx');
            $table->dropColumn(['previous_hash', 'entry_hash', 'chain_sequence']);
        });
    }
};
