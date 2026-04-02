<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the enum CHECK constraint and convert to varchar(50)
        // so any payment method code can be stored
        DB::statement("ALTER TABLE pos_payments DROP CONSTRAINT IF EXISTS pos_payments_method_check");
        DB::statement("ALTER TABLE pos_payments ALTER COLUMN method TYPE VARCHAR(50)");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE pos_payments ALTER COLUMN method TYPE VARCHAR(50)");
        DB::statement("ALTER TABLE pos_payments ADD CONSTRAINT pos_payments_method_check CHECK (method IN ('cash', 'card', 'bank_transfer', 'other'))");
    }
};
