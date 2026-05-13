<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_orders') || ! Schema::hasTable('pos_sessions') || ! Schema::hasColumn('pos_orders', 'session_id')) {
            return;
        }

        $currentSchema = $this->currentSchema();
        $targetSchema = $this->currentTargetSchema();

        if ($targetSchema === $currentSchema) {
            return;
        }

        DB::table('pos_orders')
            ->whereNotNull('session_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('pos_sessions')
                    ->whereColumn('pos_sessions.id', 'pos_orders.session_id');
            })
            ->update(['session_id' => null]);

        $quotedSchema = $this->quoteIdentifier($currentSchema);

        DB::statement('ALTER TABLE pos_orders DROP CONSTRAINT IF EXISTS pos_orders_session_id_foreign');
        DB::statement(
            "ALTER TABLE pos_orders ADD CONSTRAINT pos_orders_session_id_foreign " .
            "FOREIGN KEY (session_id) REFERENCES {$quotedSchema}.pos_sessions(id) ON DELETE SET NULL"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_orders') || ! Schema::hasTable('pos_sessions') || ! Schema::hasColumn('pos_orders', 'session_id')) {
            return;
        }

        DB::statement('ALTER TABLE pos_orders DROP CONSTRAINT IF EXISTS pos_orders_session_id_foreign');
        DB::statement(
            'ALTER TABLE pos_orders ADD CONSTRAINT pos_orders_session_id_foreign ' .
            'FOREIGN KEY (session_id) REFERENCES pos_sessions(id) ON DELETE SET NULL'
        );
    }

    private function currentSchema(): string
    {
        return DB::selectOne('select current_schema() as schema')->schema;
    }

    private function currentTargetSchema(): ?string
    {
        $row = DB::selectOne(
            <<<'SQL'
            SELECT confns.nspname AS target_schema
            FROM pg_constraint con
            JOIN pg_class cl ON cl.oid = con.conrelid
            JOIN pg_namespace ns ON ns.oid = cl.relnamespace
            JOIN pg_class confcl ON confcl.oid = con.confrelid
            JOIN pg_namespace confns ON confns.oid = confcl.relnamespace
            WHERE con.conname = 'pos_orders_session_id_foreign'
              AND ns.nspname = current_schema()
              AND cl.relname = 'pos_orders'
            LIMIT 1
            SQL
        );

        return $row?->target_schema;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
};