<?php

namespace App\Support;

/**
 * ADR-003B W6 step 0: the backfill / linking / damage reports contain
 * personal data (names, emails, phones, addresses of customers' staff).
 * `--redact` writes a copy with those values replaced, keeping ids, actions,
 * reasons, flags and country values so the report can still be reviewed
 * and shared.
 */
class ReportRedactor
{
    public const MARK = '[redacted]';

    /** Keys whose values are personal data or free-text addresses. */
    private const PERSONAL_KEYS = [
        'name', 'person', 'person_name', 'customer_name', 'display_name',
        'email', 'mobile', 'phone', 'person_email', 'person_mobile',
        'summary', 'value', 'lines', 'site_label',
    ];

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            $personal = is_string($key) && in_array($key, self::PERSONAL_KEYS, true);

            if ($personal && $key === 'lines' && is_array($value)) {
                $data[$key] = array_fill(0, count($value), self::MARK);
            } elseif (is_array($value)) {
                // e.g. the report's own `summary` section is a structure, not
                // the address `summary` text of a row: walk into it.
                $data[$key] = self::redact($value);
            } elseif ($personal && $value !== null && $value !== '') {
                $data[$key] = self::MARK;
            }
        }

        return $data;
    }
}
