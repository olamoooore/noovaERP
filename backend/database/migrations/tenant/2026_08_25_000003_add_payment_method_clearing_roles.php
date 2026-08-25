<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds CARD_CLEARING, CHEQUE_CLEARING, and GATEWAY_RECEIVABLE roles to
 * `account_roles` for #44 (policy-driven payment method account mapping).
 * Idempotent: ON CONFLICT DO NOTHING.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [
            ['code' => 'CARD_CLEARING', 'name' => 'Card Clearing', 'description' => 'Clearing account for card payments pending merchant settlement', 'is_mandatory' => false, 'category' => 'posting', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'CHEQUE_CLEARING', 'name' => 'Cheque Clearing', 'description' => 'Clearing account for cheque payments pending bank clearance', 'is_mandatory' => false, 'category' => 'posting', 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'GATEWAY_RECEIVABLE', 'name' => 'Gateway Receivable', 'description' => 'Receivable from payment gateway providers (Stripe, PayPal, etc.)', 'is_mandatory' => false, 'category' => 'posting', 'created_at' => $now, 'updated_at' => $now],
        ];

        foreach ($rows as $row) {
            DB::statement(
                "INSERT INTO account_roles (code, name, description, is_mandatory, category, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (code) DO NOTHING",
                array_values($row)
            );
        }
    }

    public function down(): void
    {
        DB::table('account_roles')->whereIn('code', ['CARD_CLEARING', 'CHEQUE_CLEARING', 'GATEWAY_RECEIVABLE'])->delete();
    }
};
