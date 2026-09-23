<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Issue #223: Laravel's built-in `encrypted` cast throws an uncaught
 * DecryptException when a row still holds plaintext (legacy rows that never
 * went through a backfill migration, or rows restored from an older backup).
 * A single such row would 500 every read of the model.
 *
 * This cast degrades gracefully: values that decrypt are returned normally,
 * unreadable values are returned as-is (legacy plaintext) with a warning —
 * matching the decrypt-or-skip resilience the backfill migrations use.
 *
 * Payload-compatible with the framework cast: reads with `decryptString` and
 * writes with `encryptString`, exactly like `encrypted` (non-serialized).
 */
class EncryptedOrLegacyString implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            Log::warning('Unreadable encrypted value returned as legacy plaintext', [
                'model' => $model::class,
                'key' => $key,
                'id' => $model->getKey(),
            ]);

            return (string) $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [$key => null];
        }

        return [$key => Crypt::encryptString((string) $value)];
    }
}
