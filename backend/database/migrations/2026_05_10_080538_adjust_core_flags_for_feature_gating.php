<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function centralFeaturesTableExists(): bool
    {
        try {
            $result = DB::selectOne(
                "select exists (
                    select 1
                    from information_schema.tables
                    where table_schema = 'public'
                      and table_name = 'features'
                ) as exists"
            );

            return (bool) ($result->exists ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!$this->centralFeaturesTableExists()) {
            return;
        }

        DB::table('public.features')
            ->whereIn('key', ['accounting', 'sales'])
            ->update([
                'is_core' => false,
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!$this->centralFeaturesTableExists()) {
            return;
        }

        DB::table('public.features')
            ->whereIn('key', ['accounting', 'sales'])
            ->update([
                'is_core' => true,
                'updated_at' => now(),
            ]);
    }
};
