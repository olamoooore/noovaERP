<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop foreign key constraints on created_by / approved_by in hrm_payroll_runs.
 *
 * In this multi-tenant app, auth()->id() returns the CENTRAL schema user ID,
 * which differs from the tenant-schema user ID for the same email address.
 * Keeping the FK causes a 23503 violation on every INSERT.
 * We store the central user ID as a plain integer instead.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hrm_payroll_runs', function (Blueprint $table) {
            // Drop FKs — ignore if they don't exist (idempotent)
            try {
                $table->dropForeign(['created_by']);
            } catch (\Exception $e) {}

            try {
                $table->dropForeign(['approved_by']);
            } catch (\Exception $e) {}
        });
    }

    public function down(): void
    {
        Schema::table('hrm_payroll_runs', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }
};
