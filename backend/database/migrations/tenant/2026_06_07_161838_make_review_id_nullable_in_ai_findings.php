<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fci_ai_findings', function (Blueprint $table) {
            $table->string('review_id')->nullable()->change();
            $table->string('account_code')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fci_ai_findings', function (Blueprint $table) {
            $table->string('review_id')->nullable(false)->change();
            $table->string('account_code')->nullable(false)->change();
        });
    }
};
