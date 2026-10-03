<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #457 (DO5): reservations carried integer quantities while stock levels and
 * movements were already decimal (#250) — a fractional delivery could not be
 * reserved or fulfilled without truncation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->decimal('quantity', 15, 4)->change();
            $table->decimal('quantity_fulfilled', 15, 4)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->change();
            $table->unsignedInteger('quantity_fulfilled')->default(0)->change();
        });
    }
};
