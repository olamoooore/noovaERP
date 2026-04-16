<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Atomic sequence — no race condition, no MAX(id)+1 hack
        DB::statement('CREATE SEQUENCE IF NOT EXISTS invoice_number_seq START 1');
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS invoice_number_seq');
    }
};
