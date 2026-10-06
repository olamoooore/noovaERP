<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire legacy AI settings keys that are no longer read, and clear any
 * stored third-party provider credentials so no unused secret remains in
 * the database.
 */
return new class extends Migration
{
    private const LEGACY_KEYS = [
        'ai.provider',
        'ai.cerebras.model',
        'ai.cerebras.api_key',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        DB::table('settings')->whereIn('key', self::LEGACY_KEYS)->delete();
    }

    public function down(): void
    {
        // Not reversible: re-creating removed credentials is never desirable.
    }
};
