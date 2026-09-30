<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-10/G-13: tax rules carry an explicit version token so determination rule
 * refs can bind exact policy versions and rule updates can enforce
 * expected-version concurrency (no last-write-wins).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('taxes', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
