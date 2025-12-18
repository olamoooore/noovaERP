<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('taxes') && !Schema::hasColumn('taxes', 'is_default')) {
            Schema::table('taxes', function (Blueprint $table) {
                $table->boolean('is_default')->default(false)->after('rate');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('taxes') && Schema::hasColumn('taxes', 'is_default')) {
            Schema::table('taxes', function (Blueprint $table) {
                $table->dropColumn('is_default');
            });
        }
    }
};
