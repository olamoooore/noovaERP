<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds 'gateway' to the payments.method CHECK constraint.
 *
 * #44 introduced a policy-driven payment-method → GL-account mapping that
 * includes a `gateway` method posting to GATEWAY_RECEIVABLE. The existing
 * payments_method_check constraint (from extend_payments_method_enum) covers
 * cash/card/bank_transfer/check/other/refund/reverse/unapply but not 'gateway',
 * so a gateway payment could never be persisted and the mapping was unreachable.
 *
 * Idempotent: rebuilds the constraint only if 'gateway' is currently missing.
 */
return new class extends Migration
{
    private const VALID_METHODS = [
        'cash',
        'card',
        'bank_transfer',
        'check',
        'other',
        'gateway',
        'refund',
        'reverse',
        'unapply',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('payments') || ! Schema::hasColumn('payments', 'method')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $def = $this->currentCheckDef();
        if ($def !== null && str_contains($def, "'gateway'")) {
            return; // already covers gateway
        }

        if ($def !== null) {
            DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_method_check');
        }

        $allowed = implode("','", self::VALID_METHODS);
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (method IN ('{$allowed}'))");
    }

    public function down(): void
    {
        // Non-destructive: leaving gateway permitted does not break flows.
    }

    private function currentCheckDef(): ?string
    {
        $row = DB::selectOne(
            "SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint
             WHERE conrelid = 'payments'::regclass
               AND conname = 'payments_method_check'
             LIMIT 1"
        );

        return $row?->def;
    }
};
