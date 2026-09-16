<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_reconciliation_reversals', function (Blueprint $table) {
            $table->string('status', 16)->default('pending')->after('reason');
            $table->timestamp('requested_at')->nullable()->after('requested_by');
            $table->foreignId('approved_by')->nullable()->change();
            $table->timestamp('reversed_at')->nullable()->change();
        });
        DB::table('bank_reconciliation_reversals')->whereNotNull('approved_by')->update(['status' => 'reversed']);
    }

    public function down(): void
    {
        DB::table('bank_reconciliation_reversals')->whereNull('approved_by')->delete();
        Schema::table('bank_reconciliation_reversals', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable(false)->change();
            $table->timestamp('reversed_at')->nullable(false)->change();
            $table->dropColumn(['status', 'requested_at']);
        });
    }
};
