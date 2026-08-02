<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_contacts', function (Blueprint $table) {
            $table->string('contact_category', 100)->nullable()->after('job_title');
            $table->string('contact_type', 100)->nullable()->after('contact_category');
        });
    }

    public function down(): void
    {
        Schema::table('customer_contacts', function (Blueprint $table) {
            $table->dropColumn(['contact_category', 'contact_type']);
        });
    }
};
