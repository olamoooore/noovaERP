<?php

namespace App\Platform\DataSecurity;

class FieldMaskingService
{
    public static function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return self::maskDefault($email);
        }

        $local = $parts[0];
        $domain = $parts[1];

        if (strlen($local) <= 1) {
            return '*'.'@'.$domain;
        }

        $masked = $local[0].str_repeat('*', max(strlen($local) - 2, 1)).$local[strlen($local) - 1];

        return $masked.'@'.$domain;
    }

    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) < 4) {
            return str_repeat('*', strlen($phone));
        }
        $last4 = substr($digits, -4);
        $masked = str_repeat('*', strlen($digits) - 4).$last4;

        return $masked;
    }

    public static function maskSSN(string $ssn): string
    {
        $digits = preg_replace('/\D/', '', $ssn);
        if (strlen($digits) < 4) {
            return str_repeat('*', strlen($ssn));
        }
        $last4 = substr($digits, -4);

        return '***-**-'.$last4;
    }

    public static function maskCard(string $card): string
    {
        $digits = preg_replace('/\D/', '', $card);
        if (strlen($digits) < 4) {
            return str_repeat('*', strlen($card));
        }
        $last4 = substr($digits, -4);

        return '****-****-****-'.$last4;
    }

    public static function maskDefault(string $value): string
    {
        $len = strlen($value);
        if ($len <= 2) {
            return str_repeat('*', $len);
        }

        return $value[0].str_repeat('*', max($len - 2, 1)).$value[$len - 1];
    }
}
