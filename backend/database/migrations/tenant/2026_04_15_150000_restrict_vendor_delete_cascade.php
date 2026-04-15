<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bills')) {
            return;
        }

        DB::statement('ALTER TABLE bills DROP CONSTRAINT IF EXISTS bills_vendor_id_foreign');
        DB::statement('ALTER TABLE bills ADD CONSTRAINT bills_vendor_id_foreign FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        if (!Schema::hasTable('bills')) {
            return;
        }

        DB::statement('ALTER TABLE bills DROP CONSTRAINT IF EXISTS bills_vendor_id_foreign');
        DB::statement('ALTER TABLE bills ADD CONSTRAINT bills_vendor_id_foreign FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE');
    }
};
