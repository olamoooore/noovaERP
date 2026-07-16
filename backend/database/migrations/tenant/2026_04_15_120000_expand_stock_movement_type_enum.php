<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_movements') || ! Schema::hasColumn('stock_movements', 'type')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_type_check');
            DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (type IN ('in', 'out', 'adjustment', 'purchase', 'sale', 'transfer_in', 'transfer_out', 'return'))");

            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE stock_movements MODIFY COLUMN type ENUM('in', 'out', 'adjustment', 'purchase', 'sale', 'transfer_in', 'transfer_out', 'return') NOT NULL DEFAULT 'out'");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_movements') || ! Schema::hasColumn('stock_movements', 'type')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_type_check');
            DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_type_check CHECK (type IN ('in', 'out', 'adjustment'))");

            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE stock_movements MODIFY COLUMN type ENUM('in', 'out', 'adjustment') NOT NULL DEFAULT 'out'");
        }
    }
};
