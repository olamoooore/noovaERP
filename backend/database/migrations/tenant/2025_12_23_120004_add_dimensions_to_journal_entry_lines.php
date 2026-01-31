<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->jsonb('dimensions')->nullable()->after('reference');
            $table->index('dimensions')->using('gin');
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropIndex(['dimensions']);
            $table->dropColumn('dimensions');
        });
    }
};
