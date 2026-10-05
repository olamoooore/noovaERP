<?php

namespace App\Support;

/**
 * Exact whole-cent allocation — splits an integer cents total over weighted
 * shares so the shares add up to the total EXACTLY (no rounding drift).
 *
 * Issue #876 (SA2) introduced this arithmetic for invoice revenue splits;
 * Issue #882 (SA9) reuses it for credit-note revenue splits (deferred vs
 * recognised) so a reclassifying reversal cannot unbalance the entry.
 */
final class CentsAllocation
{
    /**
     * @param  array<int, float|int>  $weights
     * @return array<int, int> cents per weight index, summing to $totalCents
     */
    public static function allocate(int $totalCents, array $weights): array
    {
        $sum = array_sum($weights);
        $shares = [];
        $remainders = [];

        foreach ($weights as $index => $weight) {
            $exact = $sum > 0 ? $totalCents * $weight / $sum : 0.0;
            $share = (int) floor($exact);
            $shares[$index] = $share;
            $remainders[$index] = $exact - $share;
        }

        // floor() can only undershoot (or overshoot by a cent through float
        // noise) — settle the difference on the largest fractional remainders
        // so the shares still sum to the exact total.
        $missing = $totalCents - array_sum($shares);

        if ($missing !== 0 && $shares !== []) {
            arsort($remainders);
            $order = array_keys($remainders);
            $count = count($order);
            $step = $missing > 0 ? 1 : -1;

            for ($i = 0, $settled = abs($missing); $i < $settled; $i++) {
                $shares[$order[$i % $count]] += $step;
            }
        }

        return $shares;
    }
}
