<?php

namespace Modules\POS\Models;

use Illuminate\Database\Eloquent\Model;

class PosSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected $casts = [
        'value' => 'array',
    ];

    /**
     * Get a setting value by key, with optional default.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        return $setting ? $setting->value : $default;
    }

    /**
     * Set a setting value by key (upsert).
     */
    public static function setValue(string $key, mixed $value): static
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    /**
     * Get all settings as a flat key => value array.
     */
    public static function allSettings(): array
    {
        return static::pluck('value', 'key')->toArray();
    }
}
