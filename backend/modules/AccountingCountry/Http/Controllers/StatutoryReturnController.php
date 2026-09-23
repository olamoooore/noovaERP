<?php

namespace Modules\AccountingCountry\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\AccountingCountry\Malaysia\Services\Sst02ReturnService;
use Modules\AccountingCountry\Singapore\Services\GstF5ReturnService;

/**
 * Issue #93: statutory tax-return endpoints (SG GST F5, MY SST-02) — the
 * AccountingCountry module previously had no controllers at all.
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
                ['country' => 'SG', 'form' => 'GST F5', 'authority' => 'IRAS', 'period_format' => 'YYYY-MM'],
                ['country' => 'MY', 'form' => 'SST-02', 'authority' => 'RMCD', 'period_format' => 'YYYY-MM'],
            ],
        ]);
    }

    /**
     * Generate a jurisdiction's statutory return for a reporting period.
     */
    public function show(string $country, string $period): JsonResponse
    {
        $country = strtoupper($country);

        if (! preg_match('/^\d{4}-\d{2}$/', $period)) {
            return response()->json([
                'message' => 'The reporting period must be in YYYY-MM format.',
            ], 422);
        }

        $data = match ($country) {
            'SG' => app(GstF5ReturnService::class)->generate($period),
            'MY' => app(Sst02ReturnService::class)->generate($period),
            default => null,
        };

        if ($data === null) {
            return response()->json([
                'message' => "No statutory return generator is available for country {$country}.",
            ], 404);
        }

        return response()->json(['data' => $data]);
    }
}
