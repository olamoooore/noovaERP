<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Issue #335 (Q6): quotations can now transition to `expired` when their
 * validity date passes without acceptance. The status column is a varchar
 * with a CHECK constraint (Laravel's Postgres enum), so the allowed values
 * have to be widened here.
 */
return new class extends Migration
{
    private const ALLOWED_WITH_EXPIRED = ['draft', 'sent', 'confirmed', 'cancelled', 'expired'];

    private const ALLOWED_ORIGINAL = ['draft', 'sent', 'confirmed', 'cancelled'];

    public function up(): void
    {
        $this->replaceStatusConstraint(self::ALLOWED_WITH_EXPIRED);
    }

    public function down(): void
    {
        // The original constraint cannot hold expired rows; the status is
        // terminal bookkeeping only, so return them to draft before narrowing
        // (draft is editable and can be re-validated or cancelled).
        DB::table('quotations')->where('status', 'expired')->update(['status' => 'draft']);

        $this->replaceStatusConstraint(self::ALLOWED_ORIGINAL);
    }

    /**
     * @param  string[]  $statuses
     */
    private function replaceStatusConstraint(array $statuses): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->statusCheckConstraintNames() as $constraint) {
            DB::statement('ALTER TABLE quotations DROP CONSTRAINT "'.$constraint.'"');
        }

        $allowed = implode(', ', array_map(fn (string $status) => "'".$status."'", $statuses));

        DB::statement(
            "ALTER TABLE quotations ADD CONSTRAINT quotations_status_check CHECK (status IN ({$allowed}))"
        );
    }

    /**
     * Every check constraint on quotations.status in the active schema (the
     * column definition Laravel generated has no stable name guarantee, so it
     * is resolved from the catalogue instead).
     *
     * @return string[]
     */
    private function statusCheckConstraintNames(): array
    {
        $rows = DB::select(
            'SELECT con.conname
               FROM pg_constraint con
               JOIN pg_class rel ON rel.oid = con.conrelid
               JOIN pg_namespace nsp ON nsp.oid = rel.relnamespace
              WHERE rel.relname = ? AND con.contype = ? AND nsp.nspname = current_schema()
                AND pg_get_constraintdef(con.oid) ILIKE ?',
            ['quotations', 'c', '%status%']
        );

        return array_map(fn ($row) => (string) $row->conname, $rows);
    }
};
