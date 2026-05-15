<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('entity_groups')) {
            Schema::create('entity_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code', 50)->unique();
                $table->string('base_currency', 3)->default('USD');
                $table->string('fiscal_year_start', 5)->default('01-01');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('groups') && Schema::hasTable('legal_entities') && Schema::hasColumn('legal_entities', 'group_id')) {
            $referencedGroupIds = DB::table('legal_entities')
                ->whereNotNull('group_id')
                ->distinct()
                ->pluck('group_id');

            if ($referencedGroupIds->isNotEmpty()) {
                $existingIds = DB::table('entity_groups')
                    ->whereIn('id', $referencedGroupIds)
                    ->pluck('id')
                    ->all();

                $missingIds = array_values(array_diff($referencedGroupIds->all(), $existingIds));

                if ($missingIds !== []) {
                    $groups = DB::table('groups')
                        ->select('id', 'name', 'code', 'base_currency', 'fiscal_year_start', 'created_by', 'created_at', 'updated_at')
                        ->whereIn('id', $missingIds)
                        ->get();

                    foreach ($groups as $group) {
                        DB::table('entity_groups')->updateOrInsert(
                            ['id' => $group->id],
                            [
                                'name' => $group->name,
                                'code' => $group->code,
                                'base_currency' => $group->base_currency,
                                'fiscal_year_start' => $group->fiscal_year_start,
                                'created_by' => $group->created_by,
                                'created_at' => $group->created_at,
                                'updated_at' => $group->updated_at,
                            ]
                        );
                    }

                    $maxId = DB::table('entity_groups')->max('id');
                    if ($maxId !== null) {
                        DB::statement("SELECT setval(pg_get_serial_sequence('entity_groups', 'id'), {$maxId}, true)");
                    }
                }
            }

            DB::statement('ALTER TABLE legal_entities DROP CONSTRAINT IF EXISTS legal_entities_group_id_foreign');

            DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'legal_entities_group_id_foreign'
    ) THEN
        ALTER TABLE legal_entities
            ADD CONSTRAINT legal_entities_group_id_foreign
            FOREIGN KEY (group_id)
            REFERENCES entity_groups(id)
            ON DELETE CASCADE;
    END IF;
END $$;
SQL);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('legal_entities') || !Schema::hasColumn('legal_entities', 'group_id')) {
            Schema::dropIfExists('entity_groups');
            return;
        }

        DB::statement('ALTER TABLE legal_entities DROP CONSTRAINT IF EXISTS legal_entities_group_id_foreign');

        if (Schema::hasTable('groups')) {
            DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'legal_entities_group_id_foreign'
    ) THEN
        ALTER TABLE legal_entities
            ADD CONSTRAINT legal_entities_group_id_foreign
            FOREIGN KEY (group_id)
            REFERENCES groups(id)
            ON DELETE CASCADE;
    END IF;
END $$;
SQL);
        }

        Schema::dropIfExists('entity_groups');
    }
};