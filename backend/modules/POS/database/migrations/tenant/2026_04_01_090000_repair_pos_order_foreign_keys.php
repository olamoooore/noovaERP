<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_orders')) {
            return;
        }

        if (Schema::hasColumn('pos_orders', 'session_id')) {
            DB::table('pos_orders')
                ->whereNotNull('session_id')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('pos_sessions')
                        ->whereColumn('pos_sessions.id', 'pos_orders.session_id');
                })
                ->update(['session_id' => null]);

            if (! $this->foreignKeyExists('pos_orders', 'session_id')) {
                Schema::table('pos_orders', function (Blueprint $table) {
                    $table->foreign('session_id')->references('id')->on('pos_sessions')->nullOnDelete();
                });
            }
        }

        if (Schema::hasColumn('pos_orders', 'terminal_id')) {
            DB::table('pos_orders')
                ->whereNotNull('terminal_id')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('pos_terminals')
                        ->whereColumn('pos_terminals.id', 'pos_orders.terminal_id');
                })
                ->update(['terminal_id' => null]);

            if (! $this->foreignKeyExists('pos_orders', 'terminal_id')) {
                Schema::table('pos_orders', function (Blueprint $table) {
                    $table->foreign('terminal_id')->references('id')->on('pos_terminals')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_orders')) {
            return;
        }

        Schema::table('pos_orders', function (Blueprint $table) {
            foreach (['session_id', 'terminal_id'] as $column) {
                if (Schema::hasColumn('pos_orders', $column) && $this->foreignKeyExists('pos_orders', $column)) {
                    $table->dropForeign([$column]);
                }
            }
        });
    }

    private function foreignKeyExists(string $table, string $column): bool
    {
        $schema = DB::selectOne('select current_schema() as schema')->schema;

        return DB::table('information_schema.table_constraints as tc')
            ->join('information_schema.key_column_usage as kcu', function ($join) {
                $join->on('tc.constraint_name', '=', 'kcu.constraint_name')
                    ->on('tc.table_schema', '=', 'kcu.table_schema');
            })
            ->where('tc.constraint_type', 'FOREIGN KEY')
            ->where('tc.table_schema', $schema)
            ->where('tc.table_name', $table)
            ->where('kcu.column_name', $column)
            ->exists();
    }
};
