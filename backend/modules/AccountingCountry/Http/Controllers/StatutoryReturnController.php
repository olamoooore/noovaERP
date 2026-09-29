<?php

namespace Modules\AccountingCountry\Http\Controllers;

use App\Models\StatutoryReturnFiling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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
     * Periods with live tax activity (newest first) — powers the return
     * picker so accountants see which filing periods actually contain data.
     */
    public function periods(Request $request): JsonResponse
    {
        $entityId = $request->filled('entity_id') ? (int) $request->query('entity_id') : null;

        return response()->json([
            'data' => app(\Modules\AccountingCountry\Services\TaxReturnAggregator::class)->availablePeriods($entityId),
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
     * Generalized for the filing tracker (MRD TAX-REQ-08): MY SST-02 can be
     * baselined the same way; both write the same statutory_return_filings row.
     */
    public function file(Request $request, string $country, string $period): JsonResponse
    {
        $country = strtoupper($country);

        if (! in_array($country, ['SG', 'MY'], true)) {
            return response()->json(['message' => 'Filing baselines are supported for SG (GST F5) and MY (SST-02) only.'], 422);
        }

        if (! preg_match('/^\d{4}-(\d{2}|Q[1-4])$/', $period)) {
            return response()->json(['message' => 'The reporting period must be YYYY-MM or YYYY-Qn.'], 422);
        }

        $validated = $request->validate(['entity_id' => 'nullable|integer|exists:legal_entities,id']);
        $legalEntityId = isset($validated['entity_id']) ? (int) $validated['entity_id'] : null;

        $return = $country === 'SG'
            ? app(GstF5ReturnService::class)->generate($period, $legalEntityId)
            : app(\Modules\AccountingCountry\Malaysia\Services\Sst02ReturnService::class)->generate($period, $legalEntityId);

        $boxes = $return['boxes'] ?? [
            'sales_tax_due' => $return['sales_tax']['tax_due'] ?? 0,
            'service_tax_due' => $return['service_tax']['tax_due'] ?? 0,
            'total_tax_payable' => $return['total_tax_payable'] ?? 0,
        ];

        $filing = StatutoryReturnFiling::updateOrCreate(
            [
                'country' => $country,
                'form' => $return['form'],
                'period' => $period,
                'entity_key' => StatutoryReturnFiling::entityKey($legalEntityId),
            ],
            [
                'tenant_id' => tenant()->id ?? null,
                'boxes' => $boxes,
                'filed_by' => $request->user()?->id,
                'filed_at' => now(),
            ]
        );

        return response()->json(['message' => $return['form'].' filed', 'data' => $filing], 201);
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
