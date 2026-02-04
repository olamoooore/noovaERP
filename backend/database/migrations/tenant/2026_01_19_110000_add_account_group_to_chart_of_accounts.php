<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add account_group field for Jingxi 6-digit grouping
     */
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->string('account_group', 10)->nullable()->after('code');
            $table->index('account_group');
        });
    }

    /**
     * Reverse the migration
     */
    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropIndex(['account_group']);
            $table->dropColumn('account_group');
        });
    }
};
