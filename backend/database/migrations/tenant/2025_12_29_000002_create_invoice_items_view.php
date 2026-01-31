<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Create a view for invoice_items that points to invoice_lines for compatibility
     */
    public function up(): void
    {
        DB::statement('CREATE OR REPLACE VIEW invoice_items AS SELECT * FROM invoice_lines');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS invoice_items');
    }
};
