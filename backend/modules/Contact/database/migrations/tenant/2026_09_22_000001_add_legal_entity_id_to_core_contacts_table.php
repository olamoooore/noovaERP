<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #232: core_contacts carried only the legacy uuid `entity_id` (NULL for
 * every existing row) with no column compatible with the platform entity model
 * (legal_entities.id bigint + user_entity_access). Entity scoping therefore had
 * nothing to filter on. This adds legal_entity_id so EntityAccessService-based
 * scoping can be applied.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('core_contacts') && ! Schema::hasColumn('core_contacts', 'legal_entity_id')) {
            Schema::table('core_contacts', function (Blueprint $table) {
                $table->unsignedBigInteger('legal_entity_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('core_contacts') && Schema::hasColumn('core_contacts', 'legal_entity_id')) {
            Schema::table('core_contacts', function (Blueprint $table) {
                $table->dropColumn('legal_entity_id');
            });
        }
    }
};
