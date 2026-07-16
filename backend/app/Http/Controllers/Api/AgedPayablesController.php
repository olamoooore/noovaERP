<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgedPayablesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aged Payables Controller
 * Handles aged payables report endpoints
 */
class AgedPayablesController extends Controller
{
    public function __construct(
        private AgedPayablesService $agedPayablesService
    ) {}

    /**
     * Get aged payables report
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Validate request parameters
            $validated = $request->validate([
                'search' => 'nullable|string|max:255',
                'min_amount' => 'nullable|numeric|min:0',
                'aging_bucket' => 'nullable|string|in:current,days_1_30,days_31_60,days_61_90,days_90_plus',
                'vendor_id' => 'nullable|integer|exists:vendors,id',
            ]);

            // Get report data
            $report = $this->agedPayablesService->getAgedPayables($validated);

            return response()->json([
                'data' => $report,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate aged payables report',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get bill details for a specific vendor and aging bucket
     */
    public function details(Request $request): JsonResponse
    {
        try {
            // Validate request parameters
            $validated = $request->validate([
                'vendor_id' => 'required|integer|exists:vendors,id',
                'aging_bucket' => 'required|string|in:current,days_1_30,days_31_60,days_61_90,days_90_plus',
            ]);

            // Get bill details
            $bills = $this->agedPayablesService->getBillDetails(
                $validated['vendor_id'],
                $validated['aging_bucket']
            );

            return response()->json([
                'data' => $bills,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve bill details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
