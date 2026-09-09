<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen accounting_import_staging.target_entity_id from uuid to varchar(64).
 *
 * The column is a POLYMORPHIC reference to the imported entity's primary key.
 * The imported entity types have heterogeneous id types — Bill/Invoice/Payment
 * use bigInteger ids, while the batch/staging ids themselves are uuid. Storing
 * an integer entity id (e.g. "1") into a uuid column throws
 * `invalid input syntax for type uuid`, aborting the commit transaction and
 * marking the row as errored — so imported bill/invoice/payment records could
 * never be recorded against their staging rows. Issue #51 (runtime-reproduced).
 *
 * A varchar(64) holds both integer ids and uuids, which is the correct shape
 * for a polymorphic target id. Idempotent: only converts when still uuid.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_import_staging')) {
            return;
        }

        $type = DB::scalar(
            "SELECT data_type FROM information_schema.columns
             WHERE table_name = 'accounting_import_staging' AND column_name = 'target_entity_id' LIMIT 1"
        );

        if ($type === 'uuid') {
            DB::statement(
                'ALTER TABLE accounting_import_staging
                 ALTER COLUMN target_entity_id TYPE varchar(64) USING target_entity_id::text'
            );
        }
    }

    public function down(): void
    {
        // Non-destructive: leaving a wider polymorphic id column is safe.
    }
};
