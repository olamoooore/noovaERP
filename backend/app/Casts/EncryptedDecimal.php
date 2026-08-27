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

        $decrypted = Crypt::decryptString((string) $value);

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
