<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('entity_groups')) {
            return;
        }

        Schema::table('entity_groups', function (Blueprint $table) {
            if (! Schema::hasColumn('entity_groups', 'base_currency')) {
                $table->string('base_currency', 3)->default('USD')->after('code');
            }

            if (! Schema::hasColumn('entity_groups', 'fiscal_year_start')) {
                $table->string('fiscal_year_start', 5)->default('01-01')->after('base_currency');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('entity_groups')) {
            return;
        }

        Schema::table('entity_groups', function (Blueprint $table) {
            if (Schema::hasColumn('entity_groups', 'fiscal_year_start')) {
                $table->dropColumn('fiscal_year_start');
            }

            if (Schema::hasColumn('entity_groups', 'base_currency')) {
                $table->dropColumn('base_currency');
            }
        });
    }
};
