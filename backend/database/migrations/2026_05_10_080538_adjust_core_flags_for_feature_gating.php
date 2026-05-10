<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
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
        DB::table('public.features')
            ->whereIn('key', ['accounting', 'sales'])
            ->update([
                'is_core' => true,
                'updated_at' => now(),
            ]);
    }
};
