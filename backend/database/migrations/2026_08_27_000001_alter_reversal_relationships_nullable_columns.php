<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reversal_relationships', function (Blueprint $table) {
            $table->unsignedBigInteger('reversal_journal_entry_id')->nullable()->change();
            $table->unsignedBigInteger('reversal_ledger_entry_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('reversal_relationships', function (Blueprint $table) {
            $table->unsignedBigInteger('reversal_journal_entry_id')->nullable(false)->change();
            $table->unsignedBigInteger('reversal_ledger_entry_id')->nullable(false)->change();
        });
    }
};
