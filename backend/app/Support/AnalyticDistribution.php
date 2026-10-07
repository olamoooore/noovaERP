<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Issue #332 — analytic distribution on a sales line (Odoo parity).
 *
 * A line may split its revenue across several analytic accounts by
 * percentage: [{analytic_account_id, percentage}, …] totalling 100. The
 * accounts come from the existing analytic accounts master
 * (`analytic_accounts`, Accounting → Company Settings → Analytic Accounts),
 * and each share posts to the journal on the dimension column that already
 * exists for the account's type (department_id / project_id / location_id) —
 * there is no separate dimension store.
 *
 * One shared definition for quotations, sales orders and invoices so the
 * three endpoints cannot drift.
 */
final class AnalyticDistribution
{
    /**
     * Analytic account type → journal_entry_lines column. Only these types
     * can carry a share onto the ledger (`product_line` has no journal
     * column), so only these are accepted in a distribution.
     */
    public const DIMENSION_BY_TYPE = [
        'department' => 'department_id',
        'project' => 'project_id',
        'location' => 'location_id',
    ];

    private const TOTAL_TOLERANCE = 0.01;

    /**
     * Validation rules for `<prefix>.analytic_distribution` on a line array.
     *
     * @return array<string, mixed>
     */
    public static function lineRules(string $prefix = 'lines.*'): array
    {
        return [
            "{$prefix}.analytic_distribution" => ['nullable', 'array', self::totalRule()],
            "{$prefix}.analytic_distribution.*.analytic_account_id" => [
                'required',
                'integer',
                Rule::exists('analytic_accounts', 'id')
                    ->where('is_active', true)
                    ->whereIn('type', array_keys(self::DIMENSION_BY_TYPE)),
            ],
            "{$prefix}.analytic_distribution.*.percentage" => ['required', 'numeric', 'gt:0', 'max:100'],
        ];
    }

    /**
     * The shares must total exactly 100% and name each account once.
     */
    private static function totalRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value) || $value === []) {
                return;
            }

            $total = 0.0;
            $seen = [];

            foreach ($value as $share) {
                if (! is_array($share)) {
                    $fail('Each analytic distribution share must name an analytic account and a percentage.');

                    return;
                }

                $total += is_numeric($share['percentage'] ?? null) ? (float) $share['percentage'] : 0.0;
                $accountId = $share['analytic_account_id'] ?? null;

                if ($accountId !== null && isset($seen[(string) $accountId])) {
                    $fail('Each analytic account may appear only once in a line\'s distribution.');

                    return;
                }

                $seen[(string) $accountId] = true;
            }

            if (abs($total - 100.0) > self::TOTAL_TOLERANCE) {
                $fail('The analytic distribution percentages must total 100 (got '.round($total, 4).').');
            }
        };
    }

    /**
     * Canonical stored shape; an empty / absent distribution is NULL.
     *
     * @return array<int, array{analytic_account_id: int, percentage: float}>|null
     */
    public static function normalize(mixed $distribution): ?array
    {
        if (! is_array($distribution) || $distribution === []) {
            return null;
        }

        return array_values(array_map(fn (array $share): array => [
            'analytic_account_id' => (int) $share['analytic_account_id'],
            'percentage' => round((float) $share['percentage'], 4),
        ], $distribution));
    }

    /**
     * Response-only: add each share's analytic account code / name / type to
     * the lines' distribution so the document can display it. Never saved.
     *
     * @param  iterable<\Illuminate\Database\Eloquent\Model>  $lines
     */
    public static function describeLines(iterable $lines): void
    {
        $ids = [];
        foreach ($lines as $line) {
            foreach ((array) ($line->analytic_distribution ?? []) as $share) {
                $ids[] = (int) ($share['analytic_account_id'] ?? 0);
            }
        }

        if ($ids === []) {
            return;
        }

        $accounts = DB::table('analytic_accounts')
            ->whereIn('id', array_values(array_unique($ids)))
            ->get(['id', 'code', 'name', 'type'])
            ->keyBy('id');

        foreach ($lines as $line) {
            $distribution = $line->analytic_distribution;

            if (! is_array($distribution) || $distribution === []) {
                continue;
            }

            $line->setAttribute('analytic_distribution', array_map(function (array $share) use ($accounts): array {
                $account = $accounts->get((int) ($share['analytic_account_id'] ?? 0));

                return $share + [
                    'code' => $account?->code,
                    'name' => $account?->name,
                    'type' => $account?->type,
                ];
            }, $distribution));
        }
    }

    /**
     * Expand a line's distribution into per-share journal dimensions.
     *
     * Each share keeps the line's own single dimensions and sets the column
     * its analytic account's type maps to. Returns NULL when the line has no
     * distribution (the caller keeps the line's dimensions unchanged).
     *
     * @param  array<string, int>  $lineDimensions
     * @return array<int, array{percentage: float, dimensions: array<string, int>}>|null
     */
    public static function dimensionShares(mixed $distribution, array $lineDimensions): ?array
    {
        $distribution = self::normalize($distribution);

        if ($distribution === null) {
            return null;
        }

        $types = DB::table('analytic_accounts')
            ->whereIn('id', array_column($distribution, 'analytic_account_id'))
            ->pluck('type', 'id');

        $shares = [];

        foreach ($distribution as $share) {
            $column = self::DIMENSION_BY_TYPE[$types->get($share['analytic_account_id'])] ?? null;

            if ($column === null) {
                // Never post a share without its analytic: the account was
                // removed or retyped after the line was saved.
                throw new \RuntimeException(
                    "Analytic account {$share['analytic_account_id']} in the line's distribution no longer exists or has no ledger dimension."
                );
            }

            $dimensions = $lineDimensions;
            $dimensions[$column] = $share['analytic_account_id'];

            $shares[] = [
                'percentage' => $share['percentage'],
                'dimensions' => $dimensions,
            ];
        }

        return $shares;
    }
}
