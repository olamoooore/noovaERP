<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor bill lines mirror the invoice line editor (client request
 * 2026-10-01): same columns and capture fields. Add the document-line
 * capture columns that invoice_lines already has so the shared line editor
 * can be used for AP bills too.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bill_lines')) {
            return;
        }

        Schema::table('bill_lines', function (Blueprint $tbl) {
            if (! Schema::hasColumn('bill_lines', 'model_number')) {
                $tbl->string('model_number')->nullable()->after('description');
            }
            if (! Schema::hasColumn('bill_lines', 'product_notes')) {
                $tbl->text('product_notes')->nullable()->after('model_number');
            }
            if (! Schema::hasColumn('bill_lines', 'lot_number')) {
                $tbl->string('lot_number')->nullable()->after('product_notes');
            }
            if (! Schema::hasColumn('bill_lines', 'serial_number')) {
                $tbl->string('serial_number')->nullable()->after('lot_number');
            }
            if (! Schema::hasColumn('bill_lines', 'expiry_date')) {
                $tbl->date('expiry_date')->nullable()->after('serial_number');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('bill_lines')) {
            return;
        }

        Schema::table('bill_lines', function (Blueprint $tbl) {
            foreach (['expiry_date', 'serial_number', 'lot_number', 'product_notes', 'model_number'] as $column) {
                if (Schema::hasColumn('bill_lines', $column)) {
                    $tbl->dropColumn($column);
                }
            }
        });
    }
};
