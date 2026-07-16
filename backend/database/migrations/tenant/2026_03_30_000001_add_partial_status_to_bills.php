<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE bills DROP CONSTRAINT IF EXISTS bills_status_check');
        DB::statement("ALTER TABLE bills ALTER COLUMN status SET DEFAULT 'draft'");
        DB::statement("ALTER TABLE bills ADD CONSTRAINT bills_status_check CHECK (status IN ('draft', 'posted', 'partial', 'paid', 'overdue', 'cancelled'))");
    }

    public function down(): void
    {
        DB::statement("UPDATE bills SET status = 'posted' WHERE status = 'partial'");
        DB::statement('ALTER TABLE bills DROP CONSTRAINT IF EXISTS bills_status_check');
        DB::statement("ALTER TABLE bills ALTER COLUMN status SET DEFAULT 'draft'");
        DB::statement("ALTER TABLE bills ADD CONSTRAINT bills_status_check CHECK (status IN ('draft', 'posted', 'paid', 'overdue', 'cancelled'))");
    }
};
