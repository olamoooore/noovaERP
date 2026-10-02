<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tax;
use App\Services\TaxAdvisoryService;
use App\Services\TaxCountryPackService;
use App\Services\TaxEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaxEngineController extends Controller
{
    public function __construct(
        private readonly TaxEngineService $engine,
        private readonly TaxAdvisoryService $advisory,
        private readonly TaxCountryPackService $packs,
    ) {}

    public function rules(Request $request)
    {
        $jurisdiction = strtoupper((string) $request->query('jurisdiction', 'SG'));
        $date = (string) $request->query('date', now()->toDateString());

        return response()->json([
            'data' => [
                'jurisdiction' => $jurisdiction,
                'date' => $date,
                'country_defaults' => $this->engine->countryDefaults($jurisdiction, $date),
                'configured_rules' => Tax::query()
                    ->where('is_active', true)
                    ->where(function ($q) use ($jurisdiction) {
                        $q->where('jurisdiction', $jurisdiction)->orWhereNull('jurisdiction');
                    })
                    ->orderBy('name')->get(),
            ],
        ]);
    }

    /**
     * MRD R5.1/R6.1: which country packs are active for this tenant.
     */
    public function packStatus(): JsonResponse
    {
        return response()->json(['data' => $this->packs->status()]);
    }

    /**
     * Activate a country pack as effective-dated rule DATA (idempotent by
     * code; existing tenant rules are never overwritten). Rates still require
     * verification against IRAS / LHDN-RMCD notices before a period files.
     */
    public function activatePack(string $jurisdiction): JsonResponse
    {
        try {
            return response()->json(['data' => $this->packs->activate($jurisdiction)]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * #751 TU5: pack rate changes about to take effect, with the affected open
     * quotations/sales orders (server-computed; the browser never rates).
     */
    public function upcomingChanges(Request $request): JsonResponse
    {
        $validated = $request->validate(['days' => 'nullable|integer|min:1|max:180']);

        return response()->json(['data' => $this->engine->upcomingChanges((int) ($validated['days'] ?? 30))]);
    }

    /**
     * #746 T13 AC3: products pinned to a tax rule that can no longer resolve
     * (expired / inactive / deleted / missing), with successor status.
     */
    public function expiredRuleProducts(): JsonResponse
    {
        return response()->json(['data' => $this->engine->expiredRuleProducts()]);
    }

    /**
     * #739 T6: apply the pack changes the user picked. Each rule goes through
     * the platform's own versioning path, so a rule a posted document already
     * used is end-dated and replaced rather than rewritten, and a
     * tenant-edited rule is skipped rather than silently overwritten.
     */
    public function upgradePack(Request $request, string $jurisdiction): JsonResponse
    {
        $validated = $request->validate([
            'apply' => 'required|array|min:1',
            'apply.*' => 'required|string|max:50',
        ]);

        try {
            return response()->json([
                'data' => $this->packs->upgrade($jurisdiction, $validated['apply'], $request),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * MRD R5.3/R6.2: rolling-12-month taxable value vs registration
     * thresholds (monitoring flag only - registration is a human call).
     */
    public function thresholds(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'jurisdiction' => 'nullable|string|size:2',
            'entity_id' => 'nullable|integer|exists:legal_entities,id',
            'forecast_next_12m' => 'nullable|numeric|min:0',
        ]);

        return response()->json(['data' => $this->packs->thresholds(
            isset($validated['jurisdiction']) ? strtoupper($validated['jurisdiction']) : null,
            isset($validated['entity_id']) ? (int) $validated['entity_id'] : null,
            isset($validated['forecast_next_12m']) ? (float) $validated['forecast_next_12m'] : null,
        )]);
    }

    /**
     * Deterministic tax advisory for UI/workflows. It explains the selected
     * rule and highlights configuration gaps; it never auto-files or changes
     * tax treatment.
     */
    public function advisory(Request $request)
    {
        $validated = $request->validate([
            'jurisdiction' => 'required|string|size:2',
            'direction' => 'nullable|in:input,output',
            'tax_type' => 'nullable|string|max:30',
            'amount' => 'required|numeric|min:0',
            'inclusive' => 'nullable',
            'rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $jurisdiction = strtoupper($validated['jurisdiction']);
        $direction = $validated['direction'] ?? 'output';
        $review = $this->advisory->review($this->normalizedReviewInput($validated));

        return response()->json([
            'data' => [
                'advisory_status' => $review['status'],
                'basis' => $review['basis'],
                'calculation' => $review['calculation'],
                'warnings' => $review['warnings'],
                'disclaimer' => 'This is an accounting control aid, not legal or tax advice. Validate current LHDN/RMCD or IRAS requirements before filing.',
            ],
        ]);
    }

    public function aiAdvisory(Request $request)
    {
        $validated = $request->validate([
            'jurisdiction' => 'required|string|size:2',
            'direction' => 'nullable|in:input,output',
            'tax_type' => 'nullable|string|max:30',
            'amount' => 'required|numeric|min:0',
            'inclusive' => 'nullable',
            'rate' => 'nullable|numeric|min:0|max:100',
        ]);

        return response()->json(['data' => $this->advisory->aiReview($this->normalizedReviewInput($validated))]);
    }

    /**
     * Query-string booleans arrive inconsistently ("1"/"0"/"true"/"false"/
     * absent). Cast with FILTER_VALIDATE_BOOLEAN and pass the key onward only
     * when it is a real boolean — absent/garbage means "use the resolved rule's
     * own pricing mode", never (bool) "0" == true.
     */
    private function normalizedReviewInput(array $validated): array
    {
        if (array_key_exists('inclusive', $validated)) {
            $cast = filter_var($validated['inclusive'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($cast === null) {
                unset($validated['inclusive']);
            } else {
                $validated['inclusive'] = $cast;
            }
        }

        return $validated;
    }
}
