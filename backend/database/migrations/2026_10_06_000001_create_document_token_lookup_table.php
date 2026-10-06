<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #466 (DO14) — central document-token → tenant lookup.
 *
 * Public, unauthenticated document routes (`/api/v1/do-accept/{token}`,
 * `/api/v1/invoice-accept/{token}`) carry no tenant in the host, so the owning
 * tenant must be resolved from the token itself. Scanning every tenant schema
 * per request was O(tenants) and a DoS lever; this table is the single indexed
 * lookup the resolvers use instead.
 *
 * Lives in the public (central) schema, like `tenants`/`domains`, because the
 * resolver runs before tenancy is initialized. Only the SHA-256 hash of the
 * token is stored: the token is a bearer credential and this table is readable
 * from every tenant connection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_token_lookup', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('tenant_id', 36)->index();
            $table->string('document_type', 32);
            $table->unsignedBigInteger('document_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_token_lookup');
    }
};
