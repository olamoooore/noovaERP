<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR56-01 (Issue #73) — Controlled superseding migration for `payments.payment_status`.
 *
 * The canonical enum from `2026_04_21_000001_add_correction_fields_to_documents.php` is
 * `['recorded', 'applied', 'unapplied', 'reversed', 'refunded']` with default `'recorded'`.
 *
 * The earlier convergence migration (`2026_09_14_000001_converge_correction_schema_semantics.php`)
 * was reviewer-flagged (noovaerp, 2026-09-17, HR56-01) for preserving the application-written
 * legacy values `draft`, `posted`, `cancelled` rather than converging exactly to the canonical
 * contract. The Application Layer still writes those legacy values.
 *
 * Controlled resolution (documented in this migration's header for reviewer audit):
 *
 *   1. Map legacy → canonical in a documented data-preserving way BEFORE changing
 *      the column type, with every row logged so the migration is reviewer-auditable.
 *        draft      → recorded
 *        posted     → applied
 *        cancelled  → reversed
 *
 *   2. After data normalisation, ALTER COLUMN to the canonical enum. Any
 *      pre-existing values not in canonical and not in the legacy mapping would
 *      be coerced to `recorded` (the documented default).
 *
 *   3. Apply NOT NULL + DEFAULT 'recorded' to match canonical. Per-row
 *      preservation evidence is recorded in the migration log table
 *      `payment_status_convergence_log` so the operator can audit exactly
 *      which rows were touched.
 *
 * Forward-only: down() is intentionally a no-op (see HR56 DDL no-revert policy).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments')) {
            return;
        }
        if (! Schema::hasColumn('payments', 'payment_status')) {
            return;
        }

        // 1. Create the audit log table for reviewer visibility.
        Schema::create('payment_status_convergence_log', function ($t) {
            $t->id();
            $t->unsignedBigInteger('payment_id')->nullable();
            $t->string('old_value', 30)->nullable();
            $t->string('new_value', 30)->nullable();
            $t->timestamp('converged_at')->useCurrent();
            $t->index(['payment_id']);
            $t->index('converged_at');
        });

        // 2. Record the legacy mapping BEFORE changing data, so the audit
        //    log captures every row that was touched.
        DB::statement("
            INSERT INTO payment_status_convergence_log (payment_id, old_value, new_value, converged_at)
            SELECT id, payment_status, CASE payment_status
                WHEN 'draft' THEN 'recorded'
                WHEN 'posted' THEN 'applied'
                WHEN 'cancelled' THEN 'reversed'
                ELSE 'recorded'
            END, NOW()
            FROM payments
            WHERE payment_status IN ('draft', 'posted', 'cancelled')
               OR payment_status NOT IN ('recorded', 'applied', 'unapplied', 'reversed', 'refunded')
        ");

        // 3. Apply the legacy → canonical mapping.
        DB::statement("UPDATE payments SET payment_status = 'recorded' WHERE payment_status = 'draft'");
        DB::statement("UPDATE payments SET payment_status = 'applied' WHERE payment_status = 'posted'");
        DB::statement("UPDATE payments SET payment_status = 'reversed' WHERE payment_status = 'cancelled'");

        // 4. Coerce any remaining out-of-canonical values to the documented default.
        DB::statement("
            UPDATE payments
            SET payment_status = 'recorded'
            WHERE payment_status IS NOT NULL
              AND payment_status NOT IN ('recorded', 'applied', 'unapplied', 'reversed', 'refunded')
        ");

        // 5. Drop the existing default so PostgreSQL can auto-cast during TYPE change.
        DB::statement('ALTER TABLE payments ALTER COLUMN payment_status DROP DEFAULT');

        // 6. Convert column to canonical enum via text cast.
        DB::statement('ALTER TABLE payments ALTER COLUMN payment_status TYPE varchar(20) USING payment_status::text::varchar(20)');
        DB::statement("ALTER TABLE payments ALTER COLUMN payment_status SET DEFAULT 'recorded'");
        DB::statement('ALTER TABLE payments ALTER COLUMN payment_status SET NOT NULL');

        // 7. Drop the older `draft|posted|cancelled` ENUM_PAYMENT_STATUS type if it
        //    exists from the prior convergence, and create the canonical enum.
        DB::statement('DROP TYPE IF EXISTS ENUM_PAYMENT_STATUS_LEGACY');
        DB::statement("
            DO \$\$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_type WHERE typname = 'enum_payment_status_canonical') THEN
                    CREATE TYPE enum_payment_status_canonical AS ENUM
                        ('recorded', 'applied', 'unapplied', 'reversed', 'refunded');
                END IF;
            END
            \$\$;
        ");

        // 8. Convert the varchar column to the canonical enum, with a CHECK
        //    constraint as belt-and-braces against future drift.
        DB::statement('ALTER TABLE payments ALTER COLUMN payment_status DROP DEFAULT');
        DB::statement('ALTER TABLE payments ALTER COLUMN payment_status TYPE enum_payment_status_canonical USING payment_status::text::enum_payment_status_canonical');
        DB::statement("ALTER TABLE payments ALTER COLUMN payment_status SET DEFAULT 'recorded'::enum_payment_status_canonical");
        DB::statement('ALTER TABLE payments ALTER COLUMN payment_status SET NOT NULL');

        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_payment_status_canonical_check');
        DB::statement("
            ALTER TABLE payments
            ADD CONSTRAINT payments_payment_status_canonical_check
            CHECK (payment_status IS NULL OR payment_status::text IN
                ('recorded', 'applied', 'unapplied', 'reversed', 'refunded'))
        ");

        // 9. Record migration completion marker.
        DB::table('payment_status_convergence_log')->insert([
            'payment_id' => null,
            'old_value' => '__MIGRATION_COMPLETE__',
            'new_value' => 'canonical',
            'converged_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Forward-only: see HR56 DDL no-revert policy. Intentionally empty.
        // The legacy values 'draft'/'posted'/'cancelled' are intentionally NOT
        // restored because we cannot prove which rows were originally canonical
        // vs legacy — re-introducing them would re-create the divergence this
        // migration was created to resolve.
    }
};
