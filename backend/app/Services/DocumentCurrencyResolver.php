<?php

namespace App\Services;

use App\Exceptions\CurrencyNotAvailableException;
use App\Exceptions\ExchangeRateMissingException;
use App\Models\CompanySettings;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\LegalEntity;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * DocumentCurrencyResolver — resolves the currency a commercial document is
 * denominated in and the FX snapshot carried with it.
 *
 * MRD pricelist-multicurrency-mrd FR-1.2 / FR-1.3 / FR-1.5 / FR-5.2 / §9:
 *   - effective base = legal entity base_currency when the document (or its
 *     customer) has one, else company_settings.base_currency, else USD;
 *   - rate selection = latest rate ≤ document date, manual-first tie-break;
 *     future-dated rows are used ONLY when manual (explicit override data);
 *   - a missing rate blocks posting (AC-3) with a distinct error; a stale
 *     rate (> fx.rate_staleness_days) is a non-blocking warning (§15 FR-1.5);
 *   - snapshot immutability (FR-1.7): once stamped, editing rates never
 *     mutates the document — the snapshot lives on the document row.
 *
 * The legacy resolve() keeps its Issue #98 semantics (explicit → base → USD
 * fallback) for the AP-side/Bill hooks that only need a label.
 */
class DocumentCurrencyResolver
{
    public const FALLBACK = 'USD';

    public const DEFAULT_STALENESS_DAYS = 7;

    public static function resolve(?string $explicit = null): string
    {
        if (is_string($explicit)) {
            $explicit = trim($explicit);
            if ($explicit !== '') {
                return strtoupper(substr($explicit, 0, 3));
            }
        }

        $base = CompanySettings::query()->value('base_currency');

        return is_string($base) && trim($base) !== ''
            ? strtoupper(substr(trim($base), 0, 3))
            : self::FALLBACK;
    }

    /**
     * Effective base currency for a document (FR-1.2):
     * legal entity base → customer's legal entity base → company base → USD.
     */
    public static function baseFor(?int $legalEntityId = null, ?int $customerId = null): string
    {
        if ($legalEntityId) {
            $entityBase = LegalEntity::query()->whereKey($legalEntityId)->value('base_currency');
            if (is_string($entityBase) && trim($entityBase) !== '') {
                return strtoupper(trim($entityBase));
            }
        }

        if ($customerId) {
            $customerEntity = Customer::query()->whereKey($customerId)->value('legal_entity_id');
            if ($customerEntity) {
                $entityBase = LegalEntity::query()->whereKey((int) $customerEntity)->value('base_currency');
                if (is_string($entityBase) && trim($entityBase) !== '') {
                    return strtoupper(trim($entityBase));
                }
            }
        }

        $base = CompanySettings::query()->value('base_currency');

        return is_string($base) && trim($base) !== ''
            ? strtoupper(trim($base))
            : self::FALLBACK;
    }

    /**
     * Only enabled + sellable currencies may back documents and price lists
     * (FR-1.1). Unknown codes are rejected (previously the legacy resolve()
     * blindly uppercased-and-truncated anything).
     *
     * @throws \InvalidArgumentException when the currency is not sellable
     */
    public static function assertSellable(string $code, string $context = 'document'): string
    {
        $code = strtoupper(trim($code));

        $currency = Currency::query()->where('code', $code)->first();

        if (! $currency) {
            throw new CurrencyNotAvailableException("Unknown currency '{$code}' for this {$context}.");
        }

        if (! $currency->is_active || ! $currency->is_sellable) {
            throw new CurrencyNotAvailableException("Currency '{$code}' is not enabled for {$context}s.");
        }

        return $code;
    }

    public static function isSellable(?string $code): bool
    {
        if (! is_string($code) || trim($code) === '') {
            return false;
        }

        return Currency::query()
            ->where('code', strtoupper(trim($code)))
            ->where('is_active', true)
            ->where('is_sellable', true)
            ->exists();
    }

    /**
     * Default document currency (FR-5.2): the customer's default price list
     * currency when set (and sellable), else the effective entity base.
     */
    public static function defaultCurrencyFor(?int $customerId, ?int $legalEntityId = null): string
    {
        if ($customerId) {
            $listCurrency = Customer::query()->with('priceList')
                ->whereKey($customerId)
                ->first()
                ?->priceList?->currency_code;

            if (is_string($listCurrency) && self::isSellable($listCurrency)) {
                return strtoupper($listCurrency);
            }
        }

        return self::baseFor($legalEntityId, $customerId);
    }

    /**
     * Build the full header currency snapshot for a document
     * (FR-5.1 + FR-5.2 + FR-1.3/1.5). Never trusts a pre-stamped snapshot
     * when asked to resolve fresh — call sites reuse the snapshot when the
     * document already has one (FR-5.5 conversions, FR-1.7 immutability).
     *
     * @return array{currency_code:string, exchange_rate:float, base_currency_code:string, rate_date:string, rate_source:string, rate_id:int|null, rate_stale:bool}
     *
     * @throws \RuntimeException when no rate exists at or before the date
     *                           and no manual future override is present (AC-3).
     */
    public static function snapshot(?string $explicitCurrency, ?int $customerId, ?int $legalEntityId, ?string $date = null): array
    {
        $date = $date ?? now()->toDateString();
        $base = self::baseFor($legalEntityId, $customerId);

        if ($explicitCurrency !== null && trim((string) $explicitCurrency) !== '') {
            $currency = self::assertSellable((string) $explicitCurrency);
        } else {
            $currency = self::defaultCurrencyFor($customerId, $legalEntityId);
        }

        if ($currency === $base) {
            return [
                'currency_code' => $currency,
                'exchange_rate' => 1.0,
                'base_currency_code' => $base,
                'rate_date' => $date,
                'rate_source' => 'same_currency',
                'rate_id' => null,
                'rate_stale' => false,
            ];
        }

        // Tenant may forbid foreign-currency documents entirely (§15 flag).
        if (! self::foreignCurrencyEnabled()) {
            throw new CurrencyNotAvailableException(
                "Foreign-currency documents are disabled for this tenant; currency '{$currency}' cannot be used."
            );
        }

        $resolved = app(ExchangeRateService::class)->getLatestRate($currency, $base, $date);

        if (! $resolved) {
            // FR-9.2: only a MANUAL row dated after the document date may
            // stand in — and only when the tenant keeps future-override on.
            $allowed = (bool) Setting::getValue('fx.future_rate_override_allowed', true);
            $manual = $allowed
                ? ExchangeRate::getEarliestManualRateAfter($currency, $base, $date)
                : null;

            if (! $manual) {
                throw new ExchangeRateMissingException(
                    "No exchange rate found for {$currency} to {$base} at or before {$date}. ".
                    'Record a manual rate (Administration → Multi-Currency) before this document can be posted.'
                );
            }

            // The stored manual row is {$currency}→{$base} oriented
            // (getEarliestManualRateAfter filters the exact pair), so the
            // rate is used as-is.
            $resolved = [
                'rate' => (float) $manual->rate,
                'effective_date' => $manual->effective_date->format('Y-m-d'),
                'source' => 'manual_future_override',
                'rate_id' => $manual->id,
            ];
        }

        $rate = round((float) $resolved['rate'], 6);

        if ($rate <= 0) {
            throw new ExchangeRateMissingException("Invalid exchange rate ({$rate}) for {$currency} to {$base} — record a corrected manual rate.");
        }

        $stale = self::isStale($resolved['effective_date'], $date);

        if ($stale) {
            Log::warning('DocumentCurrencyResolver: stale exchange rate snapshot', [
                'currency' => $currency,
                'base' => $base,
                'rate_date' => $resolved['effective_date'],
                'document_date' => $date,
            ]);
        }

        return [
            'currency_code' => $currency,
            'exchange_rate' => $rate,
            'base_currency_code' => $base,
            'rate_date' => $resolved['effective_date'],
            'rate_source' => $resolved['source'],
            'rate_id' => $resolved['rate_id'] ?? null,
            'rate_stale' => $stale,
        ];
    }

    /**
     * FR-1.5 staleness guard: rate older than N days (tenant-configurable
     * via Settings KV `fx.rate_staleness_days`, default 7).
     */
    public static function isStale(string $rateDate, string $documentDate): bool
    {
        $days = (int) Setting::getValue('fx.rate_staleness_days', self::DEFAULT_STALENESS_DAYS);

        if ($days <= 0) {
            return false;
        }

        return \Carbon\Carbon::parse($rateDate)->lt(\Carbon\Carbon::parse($documentDate)->subDays($days));
    }

    /**
     * Tenant staged-enablement flags (MRD §15). Both default ON — the
     * feature is backward compatible by construction (base documents keep
     * rate 1 and legacy behaviour).
     */
    public static function foreignCurrencyEnabled(): bool
    {
        return (bool) Setting::getValue('documents.foreign_currency', true);
    }

    public static function pricelistsEnabled(): bool
    {
        return (bool) Setting::getValue('pricing.pricelists', true);
    }
}
