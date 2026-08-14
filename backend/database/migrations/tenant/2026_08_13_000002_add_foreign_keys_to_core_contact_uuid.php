<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-003A: Add referential integrity for canonical identity links.
 *
 * - customers.core_contact_uuid → core_contacts.id (ORGANISATION identity)
 * - customer_contacts.core_contact_uuid → core_contacts.id (PERSON identity)
 *
 * These foreign keys prevent dangling, deleted, cross-tenant or invalid UUID
 * references (CT-VEN-003 remediation).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Clean up any dangling references before adding FK constraints.
        DB::statement('
            UPDATE customers SET core_contact_uuid = NULL
            WHERE core_contact_uuid IS NOT NULL
            AND core_contact_uuid NOT IN (SELECT id FROM core_contacts)
        ');
        DB::statement('
            UPDATE customer_contacts SET core_contact_uuid = NULL
            WHERE core_contact_uuid IS NOT NULL
            AND core_contact_uuid NOT IN (SELECT id FROM core_contacts)
        ');

        Schema::table('customers', function (Blueprint $table) {
            $table->foreign('core_contact_uuid')
                ->references('id')
                ->on('core_contacts')
                ->nullOnDelete();
        });

        Schema::table('customer_contacts', function (Blueprint $table) {
            $table->foreign('core_contact_uuid')
                ->references('id')
                ->on('core_contacts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['core_contact_uuid']);
        });

        Schema::table('customer_contacts', function (Blueprint $table) {
            $table->dropForeign(['core_contact_uuid']);
        });
    }
};
