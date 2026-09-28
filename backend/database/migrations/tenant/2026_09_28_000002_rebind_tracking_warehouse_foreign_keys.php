<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('warehouses')) {
            return;
        }

        $schema = DB::selectOne('select current_schema() as name')?->name;
        if (! is_string($schema) || $schema === '') {
            return;
        }

        $quotedSchema = '"'.str_replace('"', '""', $schema).'"';

        foreach (['product_batches', 'product_serials'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'warehouse_id')) {
                continue;
            }

            $constraint = $table.'_warehouse_id_foreign';
            DB::statement("ALTER TABLE {$quotedSchema}.{$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            DB::statement(
                "ALTER TABLE {$quotedSchema}.{$table} "
                ."ADD CONSTRAINT {$constraint} FOREIGN KEY (warehouse_id) "
                ."REFERENCES {$quotedSchema}.warehouses(id) ON DELETE SET NULL NOT VALID"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $schema = DB::selectOne('select current_schema() as name')?->name;
        if (! is_string($schema) || $schema === '') {
            return;
        }

        $quotedSchema = '"'.str_replace('"', '""', $schema).'"';
        foreach (['product_batches', 'product_serials'] as $table) {
            if (Schema::hasTable($table)) {
                DB::statement("ALTER TABLE {$quotedSchema}.{$table} DROP CONSTRAINT IF EXISTS {$table}_warehouse_id_foreign");
            }
        }
    }
};
