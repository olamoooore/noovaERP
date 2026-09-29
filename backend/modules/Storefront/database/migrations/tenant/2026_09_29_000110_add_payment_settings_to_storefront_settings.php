<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-offered OFFLINE payment methods (PAY-R13, Appendix C §C4:
     * bank transfer / pay-at-collection). Shape:
     *   {"offline": {"bank_transfer": {"enabled": bool, "label": str,
     *                "instructions": str|null}, ...}}
     * Availability is server-decided from here (FR-PAY-01 / PAY-R3) — the
     * payment-methods API merges these with the launch gateway set.
     */
    public function up(): void
    {
        Schema::table('storefront_settings', function (Blueprint $table) {
            $table->jsonb('payment_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('storefront_settings', function (Blueprint $table) {
            $table->dropColumn('payment_settings');
        });
    }
};
