<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acct_country_configurations', function (Blueprint $table) {
            if (!Schema::hasColumn('acct_country_configurations', 'provider_name')) {
                $table->string('provider_name')->default('default');
            }
            if (!Schema::hasColumn('acct_country_configurations', 'api_credentials')) {
                $table->json('api_credentials')->nullable();
            }
            if (!Schema::hasColumn('acct_country_configurations', 'test_mode')) {
                $table->boolean('test_mode')->default(true);
            }
            if (!Schema::hasColumn('acct_country_configurations', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('acct_country_configurations', function (Blueprint $table) {
            $table->dropColumn(['provider_name', 'api_credentials', 'test_mode', 'last_synced_at']);
        });
    }
};
