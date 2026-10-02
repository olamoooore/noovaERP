<?php

namespace App\Services;

/**
 * #744 T11: the ONE source of country tax facts.
 *
 * Regime, currency and rates derive from the country packs
 * (config/tax_packs.php). The country → jurisdiction/regime NAME maps
 * (geography and labelling, not rates) live in config/tax_geography.php.
 * No service class constant defines a tax rate any more, so a rate change is
 * a pack edit and nothing can drift between the engine, the determination
 * service and the `country_defaults` endpoint.
 */
class TaxPackRegistry
{
    /**
     * @return array<string, mixed>|null
     */
    public function pack(string $jurisdiction): ?array
    {
        $pack = config('tax_packs.'.strtoupper(trim($jurisdiction)));

        return is_array($pack) ? $pack : null;
    }

    /**
     * @return array<int, string>
     */
    public function jurisdictions(): array
    {
        return array_keys((array) config('tax_packs', []));
    }

    /**
     * The regime that charges for a jurisdiction: the pack's own regime first
     * (single source of truth), then the geography label map, then a generic
     * GST label for a wholly unknown jurisdiction.
     */
    public function regime(string $jurisdiction): string
    {
        $jurisdiction = strtoupper(trim($jurisdiction));

        $pack = $this->pack($jurisdiction);

        foreach ($pack['rules'] ?? [] as $rule) {
            $regime = strtoupper(trim((string) ($rule['regime'] ?? '')));

            if ($regime !== '') {
                return $regime;
            }
        }

        $mapped = config('tax_geography.country_regimes.'.$jurisdiction);

        return $mapped !== null ? strtoupper((string) $mapped) : 'GST';
    }

    public function currency(string $jurisdiction): ?string
    {
        return $this->pack($jurisdiction)['currency'] ?? null;
    }

    /**
     * country_defaults derived from the pack's rules EFFECTIVE ON $date: one
     * representative rate per rule type (standard-rated preferred, then the
     * highest rate, then the latest effective_from). A jurisdiction with no
     * pack ships no rate.
     *
     * @return array{regime:string, currency:string|null, rates:array<string, float>}
     */
    public function defaultsFor(string $jurisdiction, ?string $date = null): array
    {
        $jurisdiction = strtoupper(trim($jurisdiction));
        $date = $date ?: now()->toDateString();
        $pack = $this->pack($jurisdiction);

        $rates = [];

        if ($pack !== null) {
            /** @var array<string, array<int, array<string, mixed>>> $byType */
            $byType = [];

            foreach ($pack['rules'] ?? [] as $rule) {
                $from = $rule['effective_from'] ?? null;
                $to = $rule['effective_to'] ?? null;

                if ($from !== null && $from > $date) {
                    continue;
                }
                if ($to !== null && $to < $date) {
                    continue;
                }

                $byType[strtolower((string) ($rule['type'] ?? 'other'))][] = $rule;
            }

            foreach ($byType as $type => $rules) {
                usort($rules, function (array $a, array $b): int {
                    $aKey = [
                        ($a['treatment'] ?? 'standard_rated') === 'standard_rated' ? 0 : 1,
                        -(float) ($a['rate'] ?? 0),
                        -(int) strtotime((string) ($a['effective_from'] ?? '1970-01-01')),
                    ];
                    $bKey = [
                        ($b['treatment'] ?? 'standard_rated') === 'standard_rated' ? 0 : 1,
                        -(float) ($b['rate'] ?? 0),
                        -(int) strtotime((string) ($b['effective_from'] ?? '1970-01-01')),
                    ];

                    return $aKey <=> $bKey;
                });

                $rates[$type] = (float) ($rules[0]['rate'] ?? 0);
            }
        }

        return [
            'regime' => $this->regime($jurisdiction),
            'currency' => $this->currency($jurisdiction),
            'rates' => $rates,
        ];
    }

    /**
     * #743 T10: the pack's threshold rows effective on $date, each carrying
     * amount, currency, category, test (backward/forward/both) and source.
     *
     * @return array<int, array<string, mixed>>
     */
    public function thresholds(string $jurisdiction, ?string $date = null): array
    {
        $jurisdiction = strtoupper(trim($jurisdiction));
        $pack = $this->pack($jurisdiction);

        if ($pack === null) {
            return [];
        }

        $date = $date ?: now()->toDateString();
        $rows = [];

        foreach ($pack['thresholds'] ?? [] as $threshold) {
            if (! is_array($threshold)) {
                continue;
            }

            $from = $threshold['effective_from'] ?? null;
            $to = $threshold['effective_to'] ?? null;

            if ($from !== null && $from > $date) {
                continue;
            }
            if ($to !== null && $to < $date) {
                continue;
            }

            $rows[] = $threshold + ['jurisdiction' => $jurisdiction];
        }

        return $rows;
    }

    /**
     * Country → jurisdiction code from the geography map. Unknown codes pass
     * through unchanged (they stay their own jurisdiction).
     */
    public function jurisdictionForCountry(string $country): string
    {
        $code = strtoupper(trim($country));

        $mapped = config('tax_geography.country_jurisdictions.'.$code);

        return $mapped !== null ? strtoupper((string) $mapped) : $code;
    }
}
