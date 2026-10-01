<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product inclusive/exclusive pricing override for rule-linked and default
 * tax modes. NULL = inherit from the resolved tax rule; true = force tax
 * inclusive; false = force tax exclusive. `tax_mode='custom'` keeps using
 * `tax_included` (its own Tax Method selector).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('tax_inclusive_override')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('tax_inclusive_override');
        });
    }
};