<?php

namespace App\SuperAdmin\Application;

use Illuminate\Support\Facades\DB;

/**
 * Read-only data-provenance report over every tenant's tax audit trail.
 *
 * Why this exists: statutory figures (GST F5 / SST-02 / F7) are generated from
 * tax_transaction_details, which the PostingEngine writes for real postings —
 * but demo/seed tenants contain synthetic bulk-created documents that look
 * identical to organic ones in the report UI. This report is the platform-side
 * honesty control: it flags tenants whose tax trail shows synthetic patterns
 * so no one files (or demos a filing) from a tenant believing it is live
 * trading data.
 *
 * Classification is heuristic by design. Reasons are surfaced with every
 * verdict so a human reviewer (or the SuperAdmin console) can inspect and
 * decide — this report informs, it does not certify.
 *
 * Heuristics per tenant tax trail:
 *  - burst_ratio: max rows created within any single minute / total rows.
 *    Organic trading cannot post half of a year of tax history in one minute.
 *  - creators: distinct journal-entry authors behind the trail. A whole
 *    tenant's filing history authored by ≤ 2 accounts is synthetic-looking.
 *  - span_days: first→last creation timestamps. A "long history" of statutory
 *    periods whose rows were ALL created inside one day is backfill/demo.
 *  - dormant: live-looking trails with no new tax activity for 90+ days.
 */
class TaxProvenanceReport
{
    private const SYNTHETIC_MIN_ROWS = 10;

    public function build(): array
    {
        $tenants = DB::table('public.tenants')->select('id', 'name')->orderBy('name')->get();

        $existingSchemas = collect(DB::select('select schema_name from information_schema.schemata'))
            ->pluck('schema_name')
            ->all();

        $rows = [];

        foreach ($tenants as $tenant) {
            $rows[] = $this->evaluate($tenant, $existingSchemas);
        }

        usort($rows, fn (array $a, array $b) => [$this->riskRank($a['verdict']), $a['name']]
            <=> [$this->riskRank($b['verdict']), $b['name']]);

        return [
            'generated_at' => now()->toIso8601String(),
            'tenants' => $rows,
            'summary' => [
                'tenants' => count($rows),
                'synthetic_suspected' => count(array_filter($rows, fn ($r) => $r['verdict'] === 'synthetic_suspected')),
                'dormant' => count(array_filter($rows, fn ($r) => $r['verdict'] === 'dormant')),
                'active' => count(array_filter($rows, fn ($r) => $r['verdict'] === 'active')),
                'no_trail' => count(array_filter($rows, fn ($r) => in_array($r['verdict'], ['no_tax_activity', 'not_migrated'], true))),
            ],
        ];
    }

    /**
     * @param  array{rows:int, max_burst_per_minute:int, creators:int, span_days:int, days_since_last_activity:int|null, distinct_periods:int}  $metrics
     * @return array{verdict:string, reasons:array<int,string>}
     */
    public static function classify(array $metrics): array
    {
        $reasons = [];

        if ($metrics['rows'] === 0) {
            return ['verdict' => 'no_tax_activity', 'reasons' => ['No tax transaction trail rows.']];
        }

        $burstRatio = $metrics['rows'] > 0 ? $metrics['max_burst_per_minute'] / $metrics['rows'] : 0.0;

        if ($metrics['rows'] < self::SYNTHETIC_MIN_ROWS) {
            if ($metrics['rows'] > 0) {
                $reasons[] = sprintf('Trail is small (%d rows); pattern signals are weak.', $metrics['rows']);
            }
        } else {
            $strongPattern = false;

            if ($burstRatio >= 0.5) {
                $reasons[] = sprintf(
                    '%d of %d trail rows created within one minute (burst ratio %.0f%%).',
                    $metrics['max_burst_per_minute'],
                    $metrics['rows'],
                    $burstRatio * 100,
                );
                $strongPattern = true;
            }

            if ($metrics['span_days'] <= 2) {
                $reasons[] = sprintf('Entire trail created inside %d day(s).', max($metrics['span_days'], 1));
                $strongPattern = true;
            }

            // Decisive signal: statutory history cannot be compressed. Months
            // of filing periods materialised within ~a month of system time is
            // a bulk seed/post, not organic trading.
            if ($metrics['distinct_periods'] >= 6 && $metrics['span_days'] <= 30) {
                $reasons[] = sprintf(
                    '%d reporting periods of tax history created within %d days.',
                    $metrics['distinct_periods'],
                    max($metrics['span_days'], 1),
                );
                $strongPattern = true;
            }

            if ($metrics['creators'] <= 2 && $metrics['distinct_periods'] >= 3) {
                $reasons[] = sprintf(
                    'Tax history spanning %d reporting periods authored by %d account(s).',
                    $metrics['distinct_periods'],
                    $metrics['creators'],
                );
            }

            if ($strongPattern && count($reasons) >= 2) {
                return ['verdict' => 'synthetic_suspected', 'reasons' => $reasons];
            }
        }

        if ($metrics['days_since_last_activity'] !== null && $metrics['days_since_last_activity'] >= 90) {
            array_unshift($reasons, sprintf('No new tax activity for %d days.', $metrics['days_since_last_activity']));

            return ['verdict' => 'dormant', 'reasons' => $reasons];
        }

        if ($reasons !== []) {
            return ['verdict' => 'review', 'reasons' => $reasons];
        }

        return ['verdict' => 'active', 'reasons' => ['Creation pattern consistent with organic activity.']];
    }

    private function riskRank(string $verdict): int
    {
        return match ($verdict) {
            'synthetic_suspected' => 0,
            'dormant' => 1,
            'review' => 2,
            'active' => 3,
            default => 4,
        };
    }

    private function evaluate(object $tenant, array $existingSchemas): array
    {
        $base = [
            'tenant_id' => $tenant->id,
            'name' => $tenant->name,
        ];

        if (! in_array($tenant->id, $existingSchemas, true) || preg_match('/^[A-Za-z0-9_-]+$/', $tenant->id) !== 1) {
            return $base + ['verdict' => 'not_migrated', 'reasons' => ['Tenant schema not found.'], 'metrics' => null];
        }

        $schema = $tenant->id;

        $hasTable = DB::select(
            'select 1 from information_schema.tables where table_schema = ? and table_name = ?',
            [$schema, 'tax_transaction_details'],
        );

        if ($hasTable === []) {
            return $base + ['verdict' => 'not_migrated', 'reasons' => ['tax_transaction_details missing in tenant schema.'], 'metrics' => null];
        }

        $stats = DB::selectOne(<<<SQL
            select
                count(*) as rows,
                extract(epoch from (max(created_at) - min(created_at)))::int as span_seconds,
                max(created_at) as last_created,
                count(distinct reporting_period) as distinct_periods
            from "{$schema}".tax_transaction_details
        SQL);

        $burst = DB::selectOne(<<<SQL
            select coalesce(max(c), 0) as max_burst from (
                select count(*) c from "{$schema}".tax_transaction_details
                group by date_trunc('minute', created_at)
            ) x
        SQL);

        $creators = DB::selectOne(<<<SQL
            select count(distinct je.created_by) as creators
            from "{$schema}".tax_transaction_details ttd
            join "{$schema}".journal_entries je on je.id = ttd.journal_entry_id
        SQL);

        $metrics = [
            'rows' => (int) ($stats->rows ?? 0),
            'max_burst_per_minute' => (int) ($burst->max_burst ?? 0),
            'creators' => (int) ($creators->creators ?? 0),
            'span_days' => intdiv((int) ($stats->span_seconds ?? 0), 86400),
            'days_since_last_activity' => $stats->last_created ?? null
                ? (int) floor((time() - strtotime((string) $stats->last_created)) / 86400)
                : null,
            'distinct_periods' => (int) ($stats->distinct_periods ?? 0),
        ];

        $classification = self::classify($metrics);

        return $base + $classification + ['metrics' => $metrics];
    }
}
