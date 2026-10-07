<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #494: payment difference handling. A short payment marked "fully
 * paid" writes the difference off to the account of a tenant-configured
 * write-off reason; the write-off travels with the payment (like the #478
 * cash discount) and posts as an extra line of the payment's journal entry.
 * Additive only: existing rows get write_off_amount NULL (= no write-off).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'write_off_amount')) {
                $table->decimal('write_off_amount', 15, 2)->nullable();
            }
            if (! Schema::hasColumn('payments', 'write_off_account_id')) {
                $table->unsignedBigInteger('write_off_account_id')->nullable();
            }
            if (! Schema::hasColumn('payments', 'write_off_reason')) {
                $table->string('write_off_reason', 50)->nullable();
            }
            if (! Schema::hasColumn('payments', 'write_off_label')) {
                $table->string('write_off_label', 255)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            foreach (['write_off_amount', 'write_off_account_id', 'write_off_reason', 'write_off_label'] as $column) {
                if (Schema::hasColumn('payments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
