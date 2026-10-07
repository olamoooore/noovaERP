<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1006: an invoice's FOC line (bonus scheme free unit) must sit directly
 * under the paid line it was earned from. `is_foc` marks the free line and
 * `source_line_id` links it to that paid line so the pair survives reorders
 * and the free line is removed with its parent.
 *
 * Additive and nullable — amounts are untouched (FOC lines are already
 * zero-priced); `sequence` is only rewritten by the pairing service.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoice_lines')) {
            return;
        }

        if (! Schema::hasColumn('invoice_lines', 'is_foc')) {
            Schema::table('invoice_lines', function (Blueprint $table) {
                $table->boolean('is_foc')->default(false)->index();
            });
        }

        if (! Schema::hasColumn('invoice_lines', 'source_line_id')) {
            Schema::table('invoice_lines', function (Blueprint $table) {
                $table->foreignId('source_line_id')->nullable()->constrained('invoice_lines')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoice_lines')) {
            return;
        }

        if (Schema::hasColumn('invoice_lines', 'source_line_id')) {
            Schema::table('invoice_lines', function (Blueprint $table) {
                $table->dropConstrainedForeignId('source_line_id');
            });
        }

        if (Schema::hasColumn('invoice_lines', 'is_foc')) {
            Schema::table('invoice_lines', function (Blueprint $table) {
                $table->dropColumn('is_foc');
            });
        }
    }
};
