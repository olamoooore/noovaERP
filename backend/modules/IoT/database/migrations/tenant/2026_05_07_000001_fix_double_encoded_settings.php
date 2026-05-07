<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Fix double-encoded string values in settings table
        // The array cast was causing strings to be JSON-encoded twice
        $settings = DB::table('settings')->get();

        foreach ($settings as $setting) {
            $rawValue = $setting->value;

            if (!is_string($rawValue)) {
                continue;
            }

            // Check if it's a JSON-encoded string (double-encoded)
            $decoded = json_decode($rawValue, true);
            if (json_last_error() === JSON_ERROR_NONE && is_string($decoded)) {
                // It was double-encoded, store the decoded value
                DB::table('settings')
                    ->where('key', $setting->key)
                    ->update(['value' => $decoded]);
            }
        }
    }

    public function down(): void
    {
        // No reversal needed - the fix is idempotent
    }
};
