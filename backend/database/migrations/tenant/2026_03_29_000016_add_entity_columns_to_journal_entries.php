<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('legal_entity_id')->nullable()->after('id');
            $table->unsignedBigInteger('entity_book_id')->nullable()->after('legal_entity_id');

            $table->index('legal_entity_id');
            $table->index('entity_book_id');
        });

        // Backfill: assign all existing entries to default entity/book if they exist
        $defaultEntity = DB::table('legal_entities')
            ->where('status', 'active')
            ->orderBy('id')
            ->first();

        if ($defaultEntity) {
            $defaultBook = DB::table('entity_books')
                ->where('legal_entity_id', $defaultEntity->id)
                ->where('is_primary', true)
                ->first();

            DB::table('journal_entries')
                ->whereNull('legal_entity_id')
                ->update([
                    'legal_entity_id' => $defaultEntity->id,
                    'entity_book_id' => $defaultBook?->id,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropIndex(['legal_entity_id']);
            $table->dropIndex(['entity_book_id']);
            $table->dropColumn(['legal_entity_id', 'entity_book_id']);
        });
    }
};
