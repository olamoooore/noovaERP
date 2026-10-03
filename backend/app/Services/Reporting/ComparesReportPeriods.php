<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use Carbon\Carbon;
use Modules\FinancialReporting\DTOs\ReportFilter;

/**
 * Issue #595: period comparison for report services.
 *
 * A report asks for a comparison by setting ReportFilter::$comparisonMode
 * (`previous_period` | `same_period_last_year` | `custom`) and/or the explicit
 * compareStartDate/compareEndDate. This trait derives the comparison window,
 * re-runs the report's OWN builder for it — so both windows go through the
 * same read model, the same dimension filters, the same zero-fill and the same
 * aggregation — and attaches previous values plus Δ / Δ% to every row and to
 * the summary.
 *
 * A figure with no previous counterpart (row absent) counts as 0: the delta is
 * the full current value and the percentage is null, never a division by zero.
 * The percentage divides by |previous| so an improvement from a negative base
 * is still signed by the delta.
 *
 * Shared with the dashboards (#366): a service `use`s this trait and wraps its
 * builder with withPeriodComparison().
 */
trait ComparesReportPeriods
{
    public const COMPARISON_MODE_PREVIOUS_PERIOD = 'previous_period';

    public const COMPARISON_MODE_SAME_PERIOD_LAST_YEAR = 'same_period_last_year';

    public const COMPARISON_MODE_CUSTOM = 'custom';

    /**
     * Modes a request may ask for; the controller rejects anything else.
     *
     * @return list<string>
     */
    public static function comparisonModes(): array
    {
        return [
            self::COMPARISON_MODE_PREVIOUS_PERIOD,
            self::COMPARISON_MODE_SAME_PERIOD_LAST_YEAR,
            self::COMPARISON_MODE_CUSTOM,
        ];
    }

    /**
     * Resolve the comparison window, or null when none was requested.
     *
     * `previous_period` is the same number of calendar days immediately before
     * the primary window (5–9 Oct, 5 days → 30 Sep – 4 Oct). `same_period_last
     * year` shifts both bounds back one year, clamped at month ends (29 Feb →
     * 28 Feb). `custom` uses the explicit compare dates.
     *
     * @return array{mode: string, start_date: Carbon, end_date: Carbon}|null
     */
    public function comparisonRange(ReportFilter $filter): ?array
    {
        $mode = $filter->comparisonMode;

        if ($mode === null
            && $filter->compareStartDate !== null
            && $filter->compareEndDate !== null) {
            $mode = self::COMPARISON_MODE_CUSTOM;
        }

        if ($mode === null) {
            return null;
        }

        $start = $filter->startDate ?? Carbon::now()->startOfMonth();
        $end = $filter->endDate ?? Carbon::now()->endOfMonth();

        switch ($mode) {
            case self::COMPARISON_MODE_PREVIOUS_PERIOD:
                $days = (int) $start->diffInDays($end) + 1;
                $compareStart = $start->copy()->subDays($days);
                $compareEnd = $start->copy()->subDay();
                break;

            case self::COMPARISON_MODE_SAME_PERIOD_LAST_YEAR:
                $compareStart = $start->copy()->subYearNoOverflow();
                $compareEnd = $end->copy()->subYearNoOverflow();
                break;

            case self::COMPARISON_MODE_CUSTOM:
                if ($filter->compareStartDate === null || $filter->compareEndDate === null) {
                    return null;
                }
                $compareStart = $filter->compareStartDate->copy();
                $compareEnd = $filter->compareEndDate->copy();
                break;

            default:
                // Unknown modes are rejected by the controller; a direct
                // service caller gets no comparison rather than an exception.
                return null;
        }

        if ($compareEnd->lt($compareStart)) {
            $compareEnd = $compareStart->copy();
        }

        return [
            'mode' => $mode,
            'start_date' => $compareStart,
            'end_date' => $compareEnd,
        ];
    }

    /**
     * Add comparison values, deltas and delta percentages to a built report.
     *
     * `$build` is the report's own builder — the same callable that produced
     * `$report` — invoked once more for the comparison window with the
     * comparison settings cleared, so the two series can never diverge.
     *
     * @param  callable(ReportFilter): array  $build
     * @param  list<string>  $metrics  numeric row fields to compare
     * @param  string|null  $keyField  match rows across periods by this field,
     *                                 or null to align by position (timelines)
     */
    protected function withPeriodComparison(
        ReportFilter $filter,
        array $report,
        callable $build,
        array $metrics,
        ?string $keyField = null,
    ): array {
        $range = $this->comparisonRange($filter);

        if ($range === null) {
            return $report;
        }

        $previous = $build($filter->forRange($range['start_date'], $range['end_date']));
        $previousRows = $previous['data'] ?? [];

        $previousByKey = [];
        if ($keyField !== null) {
            foreach ($previousRows as $previousRow) {
                $previousByKey[$this->comparisonRowKey($previousRow[$keyField] ?? null)] = $previousRow;
            }
        }

        $data = [];
        foreach (($report['data'] ?? []) as $index => $row) {
            $match = $keyField === null
                ? ($previousRows[$index] ?? null)
                : ($previousByKey[$this->comparisonRowKey($row[$keyField] ?? null)] ?? null);

            foreach ($metrics as $metric) {
                if (! array_key_exists($metric, $row)) {
                    continue;
                }

                $current = is_numeric($row[$metric]) ? (float) $row[$metric] : 0.0;
                $before = is_numeric($match[$metric] ?? null) ? (float) $match[$metric] : 0.0;
                $delta = round($current - $before, 4);

                $row["{$metric}_previous"] = $before;
                $row["{$metric}_delta"] = $delta;
                $row["{$metric}_delta_percent"] = $before == 0.0
                    ? null
                    : round(($delta / abs($before)) * 100, 2);
            }

            $data[] = $row;
        }

        $report['data'] = $data;
        $report['comparison'] = [
            'mode' => $range['mode'],
            'period' => [
                'start_date' => $range['start_date']->format('Y-m-d'),
                'end_date' => $range['end_date']->format('Y-m-d'),
            ],
            'summary' => $this->comparisonSummary($report['summary'] ?? [], $previous['summary'] ?? []),
        ];

        return $report;
    }

    /** Stable match key; null ids (walk-ins, uncategorised) are a key too. */
    private function comparisonRowKey(mixed $value): string
    {
        return $value === null ? '__null__' : (string) $value;
    }

    /**
     * Δ / Δ% for every numeric summary figure. A figure absent from the
     * comparison summary counts as 0 (no previous activity) and reports a
     * null percentage.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return array<string, array{previous: float, delta: float, delta_percent: float|null}>
     */
    private function comparisonSummary(array $current, array $previous): array
    {
        $summary = [];

        foreach ($current as $key => $value) {
            if (! is_int($value) && ! is_float($value)) {
                continue;
            }

            $before = is_numeric($previous[$key] ?? null) ? (float) $previous[$key] : 0.0;
            $delta = round((float) $value - $before, 4);

            $summary[$key] = [
                'previous' => $before,
                'delta' => $delta,
                'delta_percent' => $before == 0.0
                    ? null
                    : round(($delta / abs($before)) * 100, 2),
            ];
        }

        return $summary;
    }
}
