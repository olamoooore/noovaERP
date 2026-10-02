<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #279 F13: statutory filing lifecycle — submission record fields. The state
 * machine lives on `statutory_return_filings.state`; versions stay immutable
 * (append-only from #274 G-35).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statutory_return_filings', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->string('submission_reference', 120)->nullable();
        });

        // The F13 lifecycle includes PENDING_AUTHORITY_RESPONSE (26 chars).
        Schema::table('statutory_return_filings', function (Blueprint $table) {
            $table->string('state', 40)->default('PREPARED')->change();
        });
    }

    public function down(): void
    {
        Schema::table('statutory_return_filings', function (Blueprint $table) {
            $table->dropColumn(['submitted_at', 'submitted_by', 'submission_reference']);
        });
    }
};
