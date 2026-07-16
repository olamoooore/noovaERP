<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('features')) {
            return;
        }

        DB::table('public.features')
            ->where('key', 'accounting')
            ->update([
                'is_core' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('features')) {
            return;
        }

        DB::table('public.features')
            ->where('key', 'accounting')
            ->update([
                'is_core' => false,
                'updated_at' => now(),
            ]);
    }
};
