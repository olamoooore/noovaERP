<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_terminals') || ! Schema::hasTable('pos_settings') || ! Schema::hasTable('warehouses')) {
            return;
        }

        if (! Schema::hasColumn('pos_terminals', 'warehouse_id')) {
            return;
        }

        $warehouseId = $this->resolveDefaultWarehouseId();

        if ($warehouseId === null) {
            return;
        }

        DB::table('pos_terminals')
            ->whereNull('warehouse_id')
            ->update([
                'warehouse_id' => $warehouseId,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Data backfill is intentionally not reversed.
    }

    private function resolveDefaultWarehouseId(): ?int
    {
        $value = DB::table('pos_settings')
            ->where('key', 'default_warehouse_id')
            ->value('value');

        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = $decoded ?? $value;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $warehouseId = (int) $value;

        return DB::table('warehouses')->where('id', $warehouseId)->exists()
            ? $warehouseId
            : null;
    }
};