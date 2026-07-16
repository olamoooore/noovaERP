<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasColumn('invoices', 'status')) {
            return;
        }

        DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_status_check');
        DB::statement("ALTER TABLE invoices ALTER COLUMN status SET DEFAULT 'draft'");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('draft', 'sent', 'posted', 'partial', 'paid', 'overdue', 'cancelled'))");
    }

    public function down(): void
    {
        if (! Schema::hasTable('invoices') || ! Schema::hasColumn('invoices', 'status')) {
            return;
        }

        DB::statement("UPDATE invoices SET status = 'sent' WHERE status IN ('posted', 'partial')");
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_status_check');
        DB::statement("ALTER TABLE invoices ALTER COLUMN status SET DEFAULT 'draft'");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('draft', 'sent', 'paid', 'overdue', 'cancelled'))");
    }
};
