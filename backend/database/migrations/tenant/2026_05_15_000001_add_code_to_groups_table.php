<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('entity_groups') || Schema::hasColumn('entity_groups', 'code')) {
            return;
        }

        Schema::table('entity_groups', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->after('name');
        });

        $groups = DB::table('entity_groups')
            ->select('id')
            ->orderBy('id')
            ->get();

        foreach ($groups as $group) {
            DB::table('entity_groups')
                ->where('id', $group->id)
                ->update(['code' => sprintf('GRP-%03d', $group->id)]);
        }

        DB::statement('ALTER TABLE entity_groups ALTER COLUMN code SET NOT NULL');
        DB::statement('CREATE UNIQUE INDEX entity_groups_code_unique ON entity_groups (code)');
    }

    public function down(): void
    {
        if (! Schema::hasTable('entity_groups') || ! Schema::hasColumn('entity_groups', 'code')) {
            return;
        }

        // Schema-qualified: unqualified DROP INDEX can leak to public via search_path.
        $schema = DB::selectOne('SELECT current_schema() AS s')->s;
        DB::statement("DROP INDEX IF EXISTS \"{$schema}\".entity_groups_code_unique");

        Schema::table('entity_groups', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
