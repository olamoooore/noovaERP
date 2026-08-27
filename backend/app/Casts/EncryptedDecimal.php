<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * Encrypt a decimal value at rest while exposing a normal two-decimal string
 * to Eloquent consumers. This keeps payroll calculations and resources working
 * without retaining salary/pay figures as numeric plaintext in PostgreSQL.
 */
class EncryptedDecimal implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        // Backward-compat: legacy/unencrypted rows (and test fixtures written
        // directly to the DB without going through this cast's `set`) may
        // contain a plain numeric value. Detect and return as-is instead of
        // trying to decrypt it.
        if (is_numeric($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        try {
            $decrypted = Crypt::decryptString($value);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            // Value isn't a valid encrypted payload — treat as plain text.
            // This prevents a single bad row from breaking the whole request.
            return number_format((float) $value, 2, '.', '');
        }

        return number_format((float) $decrypted, 2, '.', '');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [$key => null];
        }

        if (! is_numeric($value)) {
            throw new \InvalidArgumentException("{$key} must be numeric.");
        }

        return [$key => Crypt::encryptString(number_format((float) $value, 2, '.', ''))];
    }
}
