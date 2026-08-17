<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_contacts', function (Blueprint $table) {
            $table->softDeletes()->after('notes');
        });

        // Recreate the unique primary index to exclude soft-deleted rows
        // so that a soft-deleted contact doesn't block a new primary for the same customer.
        DB::statement('DROP INDEX IF EXISTS customer_contacts_one_primary');
        DB::statement('
            CREATE UNIQUE INDEX customer_contacts_one_primary
            ON customer_contacts (customer_id)
            WHERE is_primary = true AND deleted_at IS NULL
        ');
    }

    public function down(): void
    {
        // Restore the original index without deleted_at guard
        DB::statement('DROP INDEX IF EXISTS customer_contacts_one_primary');
        DB::statement('
            CREATE UNIQUE INDEX customer_contacts_one_primary
            ON customer_contacts (customer_id)
            WHERE is_primary = true
        ');

        Schema::table('customer_contacts', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
