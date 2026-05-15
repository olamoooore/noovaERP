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
                $nestedDecoded = json_decode($decoded, true);
                $isNestedJson = json_last_error() === JSON_ERROR_NONE;
                $value = $isNestedJson ? json_encode($nestedDecoded) : null;

                if ($isNestedJson && $value === false) {
                    continue;
                }

                // It was double-encoded; store a valid JSON value for the JSON column.
                if (DB::connection()->getDriverName() === 'pgsql') {
                    if ($isNestedJson) {
                        DB::update('UPDATE settings SET value = ?::json WHERE id = ?', [$value, $setting->id]);
                    } else {
                        DB::update('UPDATE settings SET value = to_json(?::text) WHERE id = ?', [$decoded, $setting->id]);
                    }
                } else {
                    DB::table('settings')
                        ->where('id', $setting->id)
                        ->update(['value' => $isNestedJson ? $value : json_encode($decoded)]);
                }
            }
        }
    }

    public function down(): void
    {
        // No reversal needed - the fix is idempotent
    }
};
