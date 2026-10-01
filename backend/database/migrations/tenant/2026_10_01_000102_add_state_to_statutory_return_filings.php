<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-14 (#274): statutory filings gain a submission-state dimension separate
 * from the immutable filing snapshot: FILED → ACCEPTED / REJECTED (authority
 * response recorded via the filing-tracker endpoint; full workflow in #279).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statutory_return_filings', function (Blueprint $table) {
            $table->string('state', 20)->default('FILED')->index();
            $table->string('authority_reference', 120)->nullable();
            $table->timestamp('state_updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('statutory_return_filings', function (Blueprint $table) {
            $table->dropIndex(['state']);
            $table->dropColumn(['state', 'authority_reference', 'state_updated_at']);
        });
    }
};