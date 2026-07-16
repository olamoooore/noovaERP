<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_payments')) {
            return;
        }

        if (! Schema::hasColumn('pos_payments', 'journal_entry_id')) {
            Schema::table('pos_payments', function ($table) {
                $table->unsignedBigInteger('journal_entry_id')->nullable()->after('notes');
                $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->onDelete('set null');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_payments')) {
            return;
        }

        if (Schema::hasColumn('pos_payments', 'journal_entry_id')) {
            Schema::table('pos_payments', function ($table) {
                $table->dropForeign(['journal_entry_id']);
                $table->dropColumn('journal_entry_id');
            });
        }
    }
};
