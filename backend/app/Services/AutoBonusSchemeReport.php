<?php

namespace App\Services;

use App\Models\BonusScheme;
use App\Models\CustomerBonusScheme;

/**
 * Issue #564 (BS3) — read-only clean-up report for the `Auto: <tag>` bonus
 * schemes that imports used to auto-create from medical-case tags.
 *
 * Lists every bonus scheme whose name starts with `Auto: ` and that carries
 * no rules (no `bonus_scheme_products` rows), together with the customer
 * assignments it picked up — exactly the raw material an operator needs to
 * retire them. It never writes: retiring or deleting schemes stays a
 * deliberate manual action after review (the artisan wrapper
 * `bonus-schemes:list-auto` is list-only too).
 */
class AutoBonusSchemeReport
{
    /**
     * @return array<int, array{
     *     scheme_id: int,
     *     scheme_code: string|null,
     *     name: string,
     *     status: string|null,
     *     start_date: string|null,
     *     end_date: string|null,
     *     assignment_count: int,
     *     assignments: array<int, array{
     *         id: int,
     *         customer_id: int,
     *         customer_name: string|null,
     *         status: string|null,
     *         start_date: string|null,
     *         end_date: string|null,
     *         remarks: string|null
     *     }>
     * }>
     */
    public function rows(): array
    {
        $schemes = BonusScheme::query()
            ->where('name', 'like', 'Auto: %')
            ->whereDoesntHave('products')
            ->with(['customerAssignments.customer'])
            ->orderBy('id')
            ->get();

        return $schemes->map(function (BonusScheme $scheme): array {
            $assignments = $scheme->customerAssignments
                ->map(fn (CustomerBonusScheme $assignment): array => [
                    'id' => $assignment->id,
                    'customer_id' => $assignment->customer_id,
                    'customer_name' => $assignment->customer?->name,
                    'status' => $assignment->status,
                    'start_date' => $assignment->start_date?->toDateString(),
                    'end_date' => $assignment->end_date?->toDateString(),
                    'remarks' => $assignment->remarks,
                ])
                ->values()
                ->all();

            return [
                'scheme_id' => $scheme->id,
                'scheme_code' => $scheme->scheme_code,
                'name' => $scheme->name,
                'status' => $scheme->status,
                'start_date' => $scheme->start_date?->toDateString(),
                'end_date' => $scheme->end_date?->toDateString(),
                'assignment_count' => count($assignments),
                'assignments' => $assignments,
            ];
        })->values()->all();
    }
}
