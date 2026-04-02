<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Allow null customer_id for walk-in POS sales
        DB::statement("ALTER TABLE invoices ALTER COLUMN customer_id DROP NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE invoices ALTER COLUMN customer_id SET NOT NULL");
    }
};
