<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #1004 (requester 2026-10-07): the description/section/note text becomes a
 * rich-text field (font type/size, colour, bold, italic, underline, alignment,
 * lists). The sanitized HTML is kept in `description_html`; the plain
 * `description` stays the search/export/e-invoice copy. Applies to invoice
 * lines and the sales-order lines they are generated from.
 */
return new class extends Migration
{
    private const TABLES = ['invoice_lines', 'sales_order_lines'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'description_html')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->text('description_html')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'description_html')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('description_html');
            });
        }
    }
};
