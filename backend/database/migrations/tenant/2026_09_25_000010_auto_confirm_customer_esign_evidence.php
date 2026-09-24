<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A customer e-signature recorded through the built-in signing link is already
 * verified by the customer's own action, so it must not sit in "Awaiting
 * confirmation". New rows are created confirmed (InvoiceEvidenceService);
 * this backfills the evidence recorded before that change.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('invoice_evidence')) {
            return;
        }

        DB::table('invoice_evidence')
            ->where('type', 'customer_esign')
            ->whereNull('confirmed_at')
            ->whereNull('deleted_at')
            ->update([
                'confirmed_at' => DB::raw('COALESCE(signed_at, created_at)'),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Forward-only correction: no automatic revert.
    }
};
