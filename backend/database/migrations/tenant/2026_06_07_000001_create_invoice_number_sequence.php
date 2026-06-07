<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE IF NOT EXISTS invoice_number_seq START 1');

        $maxResult = DB::selectOne("
            SELECT MAX(CAST(SUBSTRING(invoice_number FROM '\d{4}$') AS INTEGER)) as max_num
            FROM invoices
        ");

        $maxNum = (int) ($maxResult->max_num ?? 0);

        if ($maxNum > 0) {
            DB::statement("SELECT setval('invoice_number_seq', {$maxNum})");
        }
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS invoice_number_seq');
    }
};
