<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror the document-versioning columns from invoices/bills/journal_entries
 * onto the `payments` table.
 *
 * The Payment factory (and the refund/reverse correction flows) reference
 * `version_number` and `is_active_version`, but the original `payments`
 * schema never gained these columns. Without them, factory states cannot
 * seed a payment for tests and the refund service cannot stamp a new
 * version when a payment is corrected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'version_number')) {
                $table->integer('version_number')->default(1)->after('payment_status');
            }
            if (! Schema::hasColumn('payments', 'is_active_version')) {
                $table->boolean('is_active_version')->default(true)->after('version_number');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'is_active_version')) {
                $table->dropColumn('is_active_version');
            }
            if (Schema::hasColumn('payments', 'version_number')) {
                $table->dropColumn('version_number');
            }
        });
    }
};
