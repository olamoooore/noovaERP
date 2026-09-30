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
                'country_defaults' => $this->engine->countryDefaults($jurisdiction),
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
     * MRD R5.3/R6.2: rolling-12-month taxable value vs registration
     * thresholds (monitoring flag only - registration is a human call).
     */
    public function thresholds(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'jurisdiction' => 'nullable|string|size:2',
            'entity_id' => 'nullable|integer|exists:legal_entities,id',
        ]);

        return response()->json(['data' => $this->packs->thresholds(
            isset($validated['jurisdiction']) ? strtoupper($validated['jurisdiction']) : null,
            isset($validated['entity_id']) ? (int) $validated['entity_id'] : null,
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
