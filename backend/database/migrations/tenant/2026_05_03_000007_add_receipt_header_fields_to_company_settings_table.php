<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('company_settings', 'gst_registration_number')) {
                $table->string('gst_registration_number', 100)->nullable()->after('tax_id');
            }

            if (!Schema::hasColumn('company_settings', 'phone_numbers')) {
                $table->json('phone_numbers')->nullable()->after('phone');
            }

            if (!Schema::hasColumn('company_settings', 'addresses')) {
                $table->json('addresses')->nullable()->after('address');
            }

            if (!Schema::hasColumn('company_settings', 'social_links')) {
                $table->json('social_links')->nullable()->after('website');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            foreach (['gst_registration_number', 'phone_numbers', 'addresses', 'social_links'] as $column) {
                if (Schema::hasColumn('company_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
