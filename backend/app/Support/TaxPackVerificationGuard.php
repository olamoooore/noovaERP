<?php

namespace App\Support;

use Modules\AccountingCountry\Services\TaxReportingService;

/**
 * #269: rate re-verification governance.
 *
 * A country pack row whose name carries the VERIFY marker is not
 * filing-ready until compliance signs off against the enacted instrument.
 * Filing artifacts surface this warning for every period whose window
 * intersects a VERIFY row; the CI test guarantees no VERIFY row silently
 * disappears from coverage.
 */
class TaxPackVerificationGuard
{
    public static function warningFor(?string $country, string $period): ?string
    {
        if (! $country || $period === '') {
            return null;
        }

        $rows = config('tax_packs.'.strtoupper($country).'.rules', []);

        if (! is_array($rows) || $rows === []) {
            return null;
        }

        // #741 T8: compound periods include bimonthly SST-02 blocks.
        $months = preg_match('/-(?:Q[1-4]|B[1-6])$/', $period) === 1
            ? TaxReportingService::expandFilingPeriod($period, strtoupper($country))
            : [$period];

        if ($months === []) {
            return null;
        }

        sort($months);
        $periodStart = $months[0].'-01';
        $periodEnd = date('Y-m-t', strtotime(end($months).'-01'));

        $flagged = [];

        foreach ($rows as $row) {
            $name = (string) ($row['name'] ?? '');

            if (! str_contains($name, 'VERIFY')) {
                continue;
            }

            $from = $row['effective_from'] ?? null;
            $to = $row['effective_to'] ?? null;

            if ($from !== null && $from > $periodEnd) {
                continue;
            }

            if ($to !== null && $to < $periodStart) {
                continue;
            }

            $flagged[] = (string) ($row['code'] ?? $name);
        }

        if ($flagged === []) {
            return null;
        }

        return 'VERIFY-tagged rates apply to this period ('.implode(', ', $flagged)
            .'). No filing artifact may be treated as final until compliance signs off against the enacted instrument (#269).';
    }

    /**
     * Every VERIFY-tagged row in a pack, for the CI coverage check.
     *
     * @return array<int, array{code: string, from: ?string, to: ?string}>
     */
    public static function verifyTaggedRows(string $country): array
    {
        $rows = config('tax_packs.'.strtoupper($country).'.rules', []);
        $out = [];

        foreach ($rows as $row) {
            if (! str_contains((string) ($row['name'] ?? ''), 'VERIFY')) {
                continue;
            }

            $out[] = [
                'code' => (string) ($row['code'] ?? ''),
                'from' => $row['effective_from'] ?? null,
                'to' => $row['effective_to'] ?? null,
            ];
        }

        return $out;
    }
}
