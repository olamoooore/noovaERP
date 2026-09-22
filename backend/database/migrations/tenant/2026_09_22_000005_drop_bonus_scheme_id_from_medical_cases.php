<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #184 (and #188): case-count accrual is evaluated purely from the
 * customer's active CustomerBonusScheme assignments matched against each
 * item's qualifying product — a single per-case scheme column cannot support
 * the spec's multi-scheme fan-out and its FK was never constrained. Drop it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('medical_cases') || ! Schema::hasColumn('medical_cases', 'bonus_scheme_id')) {
            return;
        }

        Schema::table('medical_cases', function (Blueprint $table) {
            $table->dropColumn('bonus_scheme_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('medical_cases') || Schema::hasColumn('medical_cases', 'bonus_scheme_id')) {
            return;
        }

        Schema::table('medical_cases', function (Blueprint $table) {
            $table->unsignedBigInteger('bonus_scheme_id')->nullable();
        });
    }
};
