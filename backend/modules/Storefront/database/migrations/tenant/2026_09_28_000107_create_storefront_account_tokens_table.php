<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * storefront_account_tokens — single-use, time-boxed, HASHED tokens for
 * email verification and password reset (FR-ID-02/06). Raw tokens exist
 * only in the sent email; rotation/reuse is prevented by the unique hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_account_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('storefront_accounts')->cascadeOnDelete();
            $table->enum('purpose', ['verification', 'reset']);
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['account_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_account_tokens');
    }
};
