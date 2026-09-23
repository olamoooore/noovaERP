<?php

namespace Modules\AccountingCountry\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use App\Models\StatutoryReturnFiling;
use Modules\AccountingCountry\Malaysia\Services\Sst02ReturnService;
use Modules\AccountingCountry\Singapore\Services\GstF5ReturnService;
use Modules\AccountingCountry\Singapore\Services\GstF7ReturnService;

/**
 * Issue #93: statutory tax-return endpoints (SG GST F5, MY SST-02) — the
 * AccountingCountry module previously had no controllers at all.
 *
 * Hostile re-review (B2) extensions:
 *   - legal-entity scoping (`entity_id` query param) so a multi-entity tenant
 *     does not file one combined return;
 *   - quarterly periods (`YYYY-Qn`) alongside monthly (`YYYY-MM`) — F5 is
 *     normally quarterly;
 *   - F7 is explicitly reported as not available (split into its own issue)
 *     rather than silently absent.
 */
class StatutoryReturnController extends Controller
{
    /**
     * Returns supported by this build.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                ['country' => 'SG', 'form' => 'GST F5', 'authority' => 'IRAS', 'period_format' => 'YYYY-MM or YYYY-Qn'],
                ['country' => 'MY', 'form' => 'SST-02', 'authority' => 'RMCD', 'period_format' => 'YYYY-MM or YYYY-Qn'],
                // Issue #265: F7 discloses errors against a filed F5.
                ['country' => 'SG', 'form' => 'GST F7', 'authority' => 'IRAS', 'period_format' => 'YYYY-MM or YYYY-Qn'],
            ],
            'planned' => [],
        ]);
    }

    /**
     * Generate a jurisdiction's statutory return for a reporting period.
     *
     * Period: YYYY-MM (month) or YYYY-Qn (quarter).
     * Entity: optional `entity_id` query parameter (the tenant's legal entity).
     */
    public function show(Request $request, string $country, string $period): JsonResponse
    {
        $country = strtoupper($country);

        $months = \Modules\AccountingCountry\Services\TaxReturnAggregator::periodMonths($period);
        if ($months === null) {
            return response()->json([
                'message' => 'The reporting period must be in YYYY-MM or YYYY-Qn format.',
            ], 422);
        }

        $entityId = null;
        if ($request->filled('entity_id')) {
            $entityId = (int) $request->query('entity_id');
            $exists = \Illuminate\Support\Facades\DB::table('legal_entities')
                ->where('id', $entityId)
                ->exists();

            if (! $exists) {
                return response()->json([
                    'message' => 'The selected legal entity does not exist.',
                ], 422);
            }
        }

        $data = match ($country) {
            'SG' => app(GstF5ReturnService::class)->generate($period, $entityId),
            'MY' => app(Sst02ReturnService::class)->generate($period, $entityId),
            default => null,
        };

        if ($data === null) {
            return response()->json([
                'message' => "No statutory return generator is available for country {$country}.",
            ], 404);
        }

        $data['reporting_period'] = [
            'period' => $period,
            'months' => $months,
            'legal_entity_id' => $entityId,
        ];

        return response()->json(['data' => $data]);
    }

    /**
     * Issue #265: snapshot the current GST F5 as FILED — the F7 baseline.
     */
    public function file(Request $request, string $period): JsonResponse
    {
        if (! preg_match('/^\d{4}-(\d{2}|Q[1-4])$/', $period)) {
            return response()->json(['message' => 'The reporting period must be YYYY-MM or YYYY-Qn.'], 422);
        }

        $validated = $request->validate(['entity_id' => 'nullable|integer|exists:legal_entities,id']);
        $legalEntityId = isset($validated['entity_id']) ? (int) $validated['entity_id'] : null;

        $return = app(GstF5ReturnService::class)->generate($period, $legalEntityId);

        $filing = StatutoryReturnFiling::updateOrCreate(
            [
                'country' => 'SG',
                'form' => 'GST F5',
                'period' => $period,
                'entity_key' => StatutoryReturnFiling::entityKey($legalEntityId),
            ],
            [
                'tenant_id' => tenant()->id ?? null,
                'boxes' => $return['boxes'],
                'filed_by' => $request->user()?->id,
                'filed_at' => now(),
            ]
        );

        return response()->json(['message' => 'GST F5 filed', 'data' => $filing], 201);
    }

    /**
     * Issue #265: IRAS GST F7 — disclose errors in a previously filed F5.
     */
    public function f7(Request $request, string $period): JsonResponse
    {
        if (! preg_match('/^\d{4}-(\d{2}|Q[1-4])$/', $period)) {
            return response()->json(['message' => 'The reporting period must be YYYY-MM or YYYY-Qn.'], 422);
        }

        $validated = $request->validate(['entity_id' => 'nullable|integer|exists:legal_entities,id']);
        $legalEntityId = isset($validated['entity_id']) ? (int) $validated['entity_id'] : null;

        try {
            return response()->json([
                'data' => app(GstF7ReturnService::class)->disclose($period, $legalEntityId),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

}
