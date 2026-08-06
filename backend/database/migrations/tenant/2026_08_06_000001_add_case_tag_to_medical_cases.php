<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_cases', function (Blueprint $table) {
            $table->string('case_tag')->nullable()->after('case_no');
            $table->string('delivered_to')->nullable()->after('location');
            $table->index('case_tag');
        });
    }

    public function down(): void
    {
        Schema::table('medical_cases', function (Blueprint $table) {
            $table->dropIndex(['case_tag']);
            $table->dropColumn(['case_tag', 'delivered_to']);
        });
    }
};
