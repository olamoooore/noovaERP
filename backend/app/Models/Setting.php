<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        $row = DB::table((new static)->getTable())->where('key', $key)->first();
        if (! $row) {
            return $default;
        }

        $rawValue = $row->value ?? null;
        if ($rawValue === null) {
            return $default;
        }

        // If it's already an array, return it
        if (is_array($rawValue)) {
            return $rawValue;
        }

        // Try to decode JSON
        $decoded = json_decode($rawValue, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded ?? $default;
        }

        // Return raw value if not valid JSON
        return $rawValue;
    }

    public static function setValue(string $key, mixed $value): void
    {
        $table = (new static)->getTable();

        // PostgreSQL json column requires valid JSON, so always encode
        $storedValue = json_encode($value);

        $existing = DB::table($table)->where('key', $key)->first();

        if ($existing) {
            DB::table($table)->where('key', $key)->update(['value' => $storedValue, 'updated_at' => now()]);
        } else {
            DB::table($table)->insert(['key' => $key, 'value' => $storedValue, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
