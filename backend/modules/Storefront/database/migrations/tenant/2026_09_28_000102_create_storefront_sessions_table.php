<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * storefront_sessions — hashed, rotating, revocable account sessions
 * (FR-ID-04; decision D3 httpOnly-cookie transport binds a cookie to
 * token_hash). Guests never appear here — they carry a cart/guest token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('storefront_account_id')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('refresh_hash', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('refresh_expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('device', 160)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index(['storefront_account_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_sessions');
    }
};
