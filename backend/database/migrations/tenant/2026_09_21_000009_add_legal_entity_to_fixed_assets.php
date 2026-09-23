<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #163 (HIGH): fixed_assets had no entity/book column, so the
 * ResolveEntityContext/EnsureEntityAccess middleware on /accounting/assets had
 * nothing to scope by — any entity-scoped user could read, edit, dispose and
 * depreciate every other entity's assets.
 *
 * Adds the entity columns and backfills existing rows to the default active
 * entity/book, mirroring the journal_entries pattern from #97.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fixed_assets')) {
            return;
        }

        if (! Schema::hasColumn('fixed_assets', 'legal_entity_id')) {
            Schema::table('fixed_assets', function (Blueprint $table) {
                $table->unsignedBigInteger('legal_entity_id')->nullable()->after('id');
                $table->index('legal_entity_id');
            });
        }

        if (! Schema::hasColumn('fixed_assets', 'entity_book_id')) {
            Schema::table('fixed_assets', function (Blueprint $table) {
                $table->unsignedBigInteger('entity_book_id')->nullable()->after('legal_entity_id');
                $table->index('entity_book_id');
            });
        }

        // Backfill: assign existing assets to the default active entity/book
        // (same approach as the journal_entries entity migration).
        if (Schema::hasTable('legal_entities')) {
            $defaultEntity = DB::table('legal_entities')
                ->where('status', 'active')
                ->orderBy('id')
                ->first();

            if ($defaultEntity) {
                $defaultBookId = Schema::hasTable('entity_books')
                    ? DB::table('entity_books')
                        ->where('legal_entity_id', $defaultEntity->id)
                        ->where('is_primary', true)
                        ->value('id')
                    : null;

                DB::table('fixed_assets')
                    ->whereNull('legal_entity_id')
                    ->update([
                        'legal_entity_id' => $defaultEntity->id,
                        'entity_book_id' => $defaultBookId,
                    ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('fixed_assets')) {
            return;
        }

        if (Schema::hasColumn('fixed_assets', 'entity_book_id')) {
            Schema::table('fixed_assets', function (Blueprint $table) {
                $table->dropIndex(['entity_book_id']);
                $table->dropColumn('entity_book_id');
            });
        }

        if (Schema::hasColumn('fixed_assets', 'legal_entity_id')) {
            Schema::table('fixed_assets', function (Blueprint $table) {
                $table->dropIndex(['legal_entity_id']);
                $table->dropColumn('legal_entity_id');
            });
        }
    }
};
