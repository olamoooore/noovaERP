<?php

declare(strict_types=1);

namespace App\Tenancy;

/**
 * TXT lookups for domain ownership verification (FR-PLAT-03).
 *
 * Isolated behind this seam so tests can bind a deterministic resolver
 * instead of hitting live DNS — the same pattern as the payment-gateway
 * collaborator seams. The production implementation degrades to "no
 * records" when dns_get_record is unavailable/disabled rather than
 * throwing, so a hosting environment without DNS functions can still run
 * (verification simply reports failed/unknown, it never 500s).
 */
class DnsResolver
{
    /**
     * @return list<string> TXT strings published for the host (empty when none / unavailable)
     */
    public function txtRecords(string $host): array
    {
        if (! function_exists('dns_get_record')) {
            return [];
        }

        try {
            $records = @dns_get_record($host, DNS_TXT) ?: [];
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($records as $record) {
            if (isset($record['txt'])) {
                $out[] = (string) $record['txt'];
            }
        }

        return $out;
    }
}
