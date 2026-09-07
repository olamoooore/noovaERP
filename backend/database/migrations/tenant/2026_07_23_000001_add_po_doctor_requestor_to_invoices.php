<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'po_number')) {
                $table->string('po_number', 100)->nullable()->after('customer_reference');
            }
            if (!Schema::hasColumn('invoices', 'doctor_contact_id')) {
                $table->uuid('doctor_contact_id')->nullable()->after('po_number');
                if (Schema::hasTable('core_contacts')) {
                    $table->foreign('doctor_contact_id')->references('id')->on('core_contacts')->nullOnDelete();
                }
            }
            if (!Schema::hasColumn('invoices', 'requestor_contact_id')) {
                $table->uuid('requestor_contact_id')->nullable()->after('doctor_contact_id');
                if (Schema::hasTable('core_contacts')) {
                    $table->foreign('requestor_contact_id')->references('id')->on('core_contacts')->nullOnDelete();
                }
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'requestor_contact_id')) {
                $table->dropForeign(['requestor_contact_id']);
                $table->dropColumn('requestor_contact_id');
            }
            if (Schema::hasColumn('invoices', 'doctor_contact_id')) {
                $table->dropForeign(['doctor_contact_id']);
                $table->dropColumn('doctor_contact_id');
            }
            if (Schema::hasColumn('invoices', 'po_number')) {
                $table->dropColumn('po_number');
            }
        });
    }
};
