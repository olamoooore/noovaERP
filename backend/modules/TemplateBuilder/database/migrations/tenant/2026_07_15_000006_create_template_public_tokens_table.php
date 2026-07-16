<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-000 #169 (Public Form Service) + #176 (Secure Share) — signed public
 * access tokens for forms and surveys (ADR-004 §15).
 *
 * One row per issued signed token: carries scope, expiry, revocation,
 * rate-limit counter, replay protection (single-use nonces), and abuse
 * monitoring counters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('template_public_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // The signed token hash (the raw token is never stored).
            $table->string('token_hash', 128)->unique();
            $table->uuid('template_id')->nullable()->index();
            $table->string('interaction_type', 24)->index();

            $table->string('channel', 24)->default('public_link');
            // Scope constraints carried into resolution (entity, branch, language...).
            $table->json('scope')->nullable();
            // What the holder may do: respond, view, submit.
            $table->string('capability', 24)->default('respond');

            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason')->nullable();

            // Rate limiting & replay protection.
            $table->unsignedInteger('max_uses')->default(0); // 0 = unlimited
            $table->unsignedInteger('use_count')->default(0);
            $table->unsignedInteger('rate_limit_per_hour')->default(60);
            $table->timestamp('last_used_at')->nullable();

            // Abuse monitoring.
            $table->string('issued_to_email', 191)->nullable();
            $table->string('issued_ip', 45)->nullable();
            $table->json('metadata')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Replay-protection: each consumed nonce may be used once.
        Schema::create('template_public_token_nonces', function (Blueprint $table) {
            $table->id();
            $table->uuid('token_id')->index();
            $table->string('nonce', 120);
            $table->timestamp('used_at')->useCurrent();
            $table->unique(['token_id', 'nonce']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_public_token_nonces');
        Schema::dropIfExists('template_public_tokens');
    }
};
