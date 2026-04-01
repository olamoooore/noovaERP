<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add missing columns to tenant pos_orders table.
     * These were previously only added by a central migration.
     */
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_orders', 'session_id')) {
                $table->unsignedBigInteger('session_id')->nullable()->after('id');
            }

            if (!Schema::hasColumn('pos_orders', 'terminal_id')) {
                $table->unsignedBigInteger('terminal_id')->nullable()->after('session_id');
            }

            if (!Schema::hasColumn('pos_orders', 'customer_id')) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('terminal_id');
                $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            }

            if (!Schema::hasColumn('pos_orders', 'is_return')) {
                $table->boolean('is_return')->default(false);
            }

            if (!Schema::hasColumn('pos_orders', 'original_order_id')) {
                $table->foreignId('original_order_id')->nullable()->constrained('pos_orders')->nullOnDelete();
            }

            if (!Schema::hasColumn('pos_orders', 'loyalty_points_earned')) {
                $table->integer('loyalty_points_earned')->default(0);
            }

            if (!Schema::hasColumn('pos_orders', 'loyalty_points_redeemed')) {
                $table->integer('loyalty_points_redeemed')->default(0);
            }

            if (!Schema::hasColumn('pos_orders', 'loyalty_discount')) {
                $table->decimal('loyalty_discount', 12, 2)->default(0);
            }

            if (!Schema::hasColumn('pos_orders', 'receipt_number')) {
                $table->string('receipt_number')->nullable();
            }

            if (!Schema::hasColumn('pos_orders', 'cashier_name')) {
                $table->string('cashier_name')->nullable();
            }
        });

        if (Schema::hasColumn('pos_orders', 'session_id') && ! $this->foreignKeyExists('pos_orders', 'session_id')) {
            Schema::table('pos_orders', function (Blueprint $table) {
                $table->foreign('session_id')->references('id')->on('pos_sessions')->nullOnDelete();
            });
        }

        if (Schema::hasColumn('pos_orders', 'terminal_id') && ! $this->foreignKeyExists('pos_orders', 'terminal_id')) {
            Schema::table('pos_orders', function (Blueprint $table) {
                $table->foreign('terminal_id')->references('id')->on('pos_terminals')->nullOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $table) {
            $columns = [
                'session_id', 'terminal_id', 'customer_id', 'is_return',
                'original_order_id', 'loyalty_points_earned', 'loyalty_points_redeemed',
                'loyalty_discount', 'receipt_number', 'cashier_name',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('pos_orders', $col)) {
                    if (in_array($col, ['session_id', 'terminal_id', 'customer_id', 'original_order_id']) && $this->foreignKeyExists('pos_orders', $col)) {
                        $table->dropForeign(["$col"]);
                    }
                    $table->dropColumn($col);
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
