<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-003A CT-VEN-019: Snapshot original legacy values on customer_contacts.
 *
 * After commit, the CustomerContact row preserves a snapshot of the original
 * legacy compatibility fields (contact_category, contact_type, billing_address)
 * for reconciliation against issued transactions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_contacts', function (Blueprint $table) {
            $table->json('original_legacy_values')->nullable()->after('department_name');
        });
    }

    public function down(): void
    {
        Schema::table('customer_contacts', function (Blueprint $table) {
            $table->dropColumn('original_legacy_values');
        });
    }
};
