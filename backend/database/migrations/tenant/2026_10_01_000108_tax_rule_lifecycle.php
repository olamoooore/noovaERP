<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #276 F2: tax-rule lifecycle DRAFT -> PENDING_APPROVAL -> ACTIVE ->
 * SUPERSEDED/RETIRED. Default ACTIVE keeps every existing rule resolvable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->string('lifecycle_state', 20)->default('ACTIVE')->index();
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->dropIndex(['lifecycle_state']);
            $table->dropColumn(['lifecycle_state', 'submitted_by', 'approved_by', 'approved_at']);
        });
    }
};
