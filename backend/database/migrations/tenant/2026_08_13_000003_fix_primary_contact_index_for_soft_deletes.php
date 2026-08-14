<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-003A: Ensure primary-contact unique index is correct.
 *
 * Recreates the customer_contacts_one_primary index to confirm its
 * definition. The original index is correct for the current schema
 * (customer_contacts does NOT use SoftDeletes, so all rows are
 * logically active). Soft-deleted customers are handled at the
 * application level: the setPrimaryContact() method verifies the
 * customer is not soft-deleted before proceeding.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS customer_contacts_one_primary');

        // One active CRM primary per customer account (CT-VEN-007/028).
        // customer_contacts does NOT use SoftDeletes, so all rows in this
        // table are logically active. Soft-deleted customers are checked
        // at the application level (setPrimaryContact verifies customer
        // exists and is not deleted before proceeding).
        DB::statement('
            CREATE UNIQUE INDEX customer_contacts_one_primary
            ON customer_contacts (customer_id)
            WHERE is_primary = true
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS customer_contacts_one_primary');

        DB::statement('
            CREATE UNIQUE INDEX customer_contacts_one_primary
            ON customer_contacts (customer_id)
            WHERE is_primary = true
        ');
    }
};
