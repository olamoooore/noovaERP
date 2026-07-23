<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('customers')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'road_number')) {
                $table->string('road_number', 50)->nullable()->after('shipping_address');
            }
            if (!Schema::hasColumn('customers', 'road_name')) {
                $table->string('road_name', 200)->nullable()->after('road_number');
            }
            if (!Schema::hasColumn('customers', 'level_unit')) {
                $table->string('level_unit', 50)->nullable()->after('road_name');
            }
            if (!Schema::hasColumn('customers', 'building_name')) {
                $table->string('building_name', 200)->nullable()->after('level_unit');
            }
            if (!Schema::hasColumn('customers', 'state')) {
                $table->string('state', 100)->nullable()->after('building_name');
            }
            if (!Schema::hasColumn('customers', 'zip_code')) {
                $table->string('zip_code', 20)->nullable()->after('state');
            }
            if (!Schema::hasColumn('customers', 'country')) {
                $table->string('country', 100)->nullable()->after('zip_code');
            }
        });

        // Add billing/shipping contact links to invoices
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'billing_contact_id')) {
                $table->unsignedBigInteger('billing_contact_id')->nullable()->after('requestor_contact_id');
                $table->foreign('billing_contact_id')->references('id')->on('core_contacts')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoices', 'shipping_contact_id')) {
                $table->unsignedBigInteger('shipping_contact_id')->nullable()->after('billing_contact_id');
                $table->foreign('shipping_contact_id')->references('id')->on('core_contacts')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                if (Schema::hasColumn('invoices', 'shipping_contact_id')) {
                    $table->dropForeign(['shipping_contact_id']);
                    $table->dropColumn('shipping_contact_id');
                }
                if (Schema::hasColumn('invoices', 'billing_contact_id')) {
                    $table->dropForeign(['billing_contact_id']);
                    $table->dropColumn('billing_contact_id');
                }
            });
        }

        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                $cols = ['country', 'zip_code', 'state', 'building_name', 'level_unit', 'road_name', 'road_number'];
                foreach ($cols as $col) {
                    if (Schema::hasColumn('customers', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
