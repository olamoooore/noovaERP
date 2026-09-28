<?php

namespace Modules\Storefront\Support;

use App\Models\Currency;

/**
 * Tenant-neutral price presentation helpers (FR-SF-10).
 *
 * Currency comes from the tenant's storefront settings — never a constant.
 * When the tenant has not configured a currency code, amounts render with
 * the code (or plain) rather than guessing, so no tenant-specific default
 * can ever leak into another tenant's storefront (G5).
 */
final class StorefrontFormatter
{
    /** @var array<string,array<string,mixed>|false> per-request memo */
    private static array $currencyMemo = [];

    private static ?string $memoTenant = null;

    public static function price(?string $currencyCode, float $amount): string
    {
        $formatted = number_format($amount, 2);

        if ($currencyCode === null || $currencyCode === '') {
            return $formatted;
        }

        $currency = self::currency($currencyCode);
        $symbol = $currency['symbol'] ?? null;

        if ($symbol !== null && $symbol !== '') {
            return $symbol.$formatted;
        }

        return $formatted.' '.$currencyCode;
    }

    public static function taxNote(string $taxDisplay): string
    {
        return $taxDisplay === 'inclusive'
            ? 'Prices include applicable tax.'
            : 'Prices exclude applicable tax.';
    }

    /**
     * @return array<string,mixed>
     */
    private static function currency(string $code): array
    {
        // `currencies` is a TENANT table: the memo is tenant-scoped AND reset
        // on tenant switch, so one tenant's symbol can never bleed into
        // another within the same PHP worker (NFR-01).
        $tenant = self::tenantKey();
        if (self::$memoTenant !== $tenant) {
            self::$currencyMemo = [];
            self::$memoTenant = $tenant;
        }

        if (! array_key_exists($code, self::$currencyMemo)) {
            $row = Currency::query()->where('code', $code)->first(['code', 'symbol', 'decimal_places']);
            self::$currencyMemo[$code] = $row ? $row->toArray() : false;
        }

        $cached = self::$currencyMemo[$code];

        return is_array($cached) ? $cached : [];
    }

    private static function tenantKey(): string
    {
        if (function_exists('tenant') && tenant()) {
            return (string) tenant('id');
        }

        return 'central';
    }

    /** Test seam: reset the per-request memo. */
    public static function resetMemo(): void
    {
        self::$currencyMemo = [];
    }
}
