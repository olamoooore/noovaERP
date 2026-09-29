<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FR-MSG-03 versioning (minimal, honest): every communication template row
 * carries an integer version, stamped by seeders and bumped by editors when
 * the copy changes. Combined with per-tenant override rows (same key),
 * evolving a system default never silently rewrites a tenant's customised
 * template, and renderers/ops can tell WHICH copy produced a send.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('communication_templates') && ! Schema::hasColumn('communication_templates', 'template_version')) {
            Schema::table('communication_templates', function (Blueprint $table) {
                $table->unsignedSmallInteger('template_version')->default(1)->after('is_system');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('communication_templates') && Schema::hasColumn('communication_templates', 'template_version')) {
            Schema::table('communication_templates', function (Blueprint $table) {
                $table->dropColumn('template_version');
            });
        }
    }
};
