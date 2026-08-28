<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-003A / ADR-005 §20.2: Vendor and Supplier identity alignment with the
 * canonical Contact Master.
 *
 * - vendors.core_contact_uuid: canonical ORGANISATION identity link (CT-VEN-003)
 *   so the vendor table (AP) is no longer a duplicate master from Contact.
 * - suppliers.core_contact_uuid (modules/Inventory/Models/Supplier): same
 *   canonical identity, so Procurement's Supplier model also links to the
 *   same canonical contact as AP's Vendor when one is created for the other.
 *
 * Both columns are nullable and backfilled by VendorContactSyncService.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendors') && ! Schema::hasColumn('vendors', 'core_contact_uuid')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->uuid('core_contact_uuid')->nullable()->after('id')->index();
            });
        }

        if (Schema::hasTable('suppliers') && ! Schema::hasColumn('suppliers', 'core_contact_uuid')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->uuid('core_contact_uuid')->nullable()->after('id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vendors') && Schema::hasColumn('vendors', 'core_contact_uuid')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->dropColumn('core_contact_uuid');
            });
        }

        if (Schema::hasTable('suppliers') && Schema::hasColumn('suppliers', 'core_contact_uuid')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->dropColumn('core_contact_uuid');
            });
        }
    }
};
