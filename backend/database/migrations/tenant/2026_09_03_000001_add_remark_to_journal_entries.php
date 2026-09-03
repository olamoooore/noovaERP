<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('journal_entries', 'remark')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->string('remark', 255)->nullable()->after('description');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('journal_entries', 'remark')) {
            Schema::table('journal_entries', function (Blueprint $table) {
                $table->dropColumn('remark');
            });
        }
    }
};
