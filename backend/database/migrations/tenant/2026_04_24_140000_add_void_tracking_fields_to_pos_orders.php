<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_orders')) {
            return;
        }

        Schema::table('pos_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('pos_orders', 'voided_by')) {
                $table->unsignedBigInteger('voided_by')->nullable()->after('voided_at');
            }

            if (! Schema::hasColumn('pos_orders', 'void_approved_by')) {
                $table->unsignedBigInteger('void_approved_by')->nullable()->after('voided_by');
            }

            if (! Schema::hasColumn('pos_orders', 'void_reason')) {
                $table->string('void_reason', 500)->nullable()->after('void_approved_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_orders')) {
            return;
        }

        Schema::table('pos_orders', function (Blueprint $table) {
            foreach (['voided_by', 'void_approved_by', 'void_reason'] as $column) {
                if (Schema::hasColumn('pos_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};