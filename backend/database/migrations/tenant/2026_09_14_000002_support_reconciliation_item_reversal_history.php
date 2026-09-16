<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_items', fn (Blueprint $table) => $table->timestamp('reversed_at')->nullable());
        DB::statement('ALTER TABLE reconciliation_items DROP CONSTRAINT IF EXISTS reconciliation_items_journal_entry_line_id_unique');
        DB::statement('CREATE UNIQUE INDEX reconciliation_items_active_gl_unique ON reconciliation_items (journal_entry_line_id) WHERE reversed_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS reconciliation_items_active_gl_unique');
        Schema::table('reconciliation_items', fn (Blueprint $table) => $table->dropColumn('reversed_at'));
        Schema::table('reconciliation_items', fn (Blueprint $table) => $table->unique('journal_entry_line_id'));
    }
};
