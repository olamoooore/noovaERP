<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Server-authoritative carts (FR-CO-02). Identity is either an account
 * session or a hashed guest token — never client storage. Exactly one
 * ACTIVE cart per account and per guest token (partial uniques, E2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained('storefront_accounts')->cascadeOnDelete();
            $table->string('guest_token_hash', 64)->nullable();
            $table->string('currency', 3)->nullable();
            $table->enum('status', ['active', 'ordered', 'abandoned', 'expired'])->default('active');
            $table->timestamp('abandoned_emitted_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });

        Schema::create('storefront_cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('storefront_carts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->unsignedBigInteger('variant_id')->nullable()->index();
            $table->decimal('quantity', 12, 3)->default(1);
            $table->timestamps();

            $table->index('product_id');
        });

        $schema = DB::selectOne('SELECT current_schema() AS s')->s;
        DB::statement("CREATE UNIQUE INDEX storefront_carts_active_per_account ON \"{$schema}\".storefront_carts (account_id) WHERE account_id IS NOT NULL AND status = 'active'");
        DB::statement("CREATE UNIQUE INDEX storefront_carts_active_per_guest ON \"{$schema}\".storefront_carts (guest_token_hash) WHERE guest_token_hash IS NOT NULL AND status = 'active'");
        DB::statement("CREATE UNIQUE INDEX storefront_cart_items_unique_line ON \"{$schema}\".storefront_cart_items (cart_id, product_id, COALESCE(variant_id, 0))");
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_cart_items');
        Schema::dropIfExists('storefront_carts');
    }
};
