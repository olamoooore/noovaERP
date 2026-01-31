<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            // Add account_type column if it doesn't exist
            if (!Schema::hasColumn('chart_of_accounts', 'account_type')) {
                $table->string('account_type', 50)->nullable()->after('account_code');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('chart_of_accounts', 'account_type')) {
                $table->dropColumn('account_type');
            }
        });
    }
};
