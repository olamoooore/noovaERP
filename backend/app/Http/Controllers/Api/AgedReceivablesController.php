<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgedReceivablesService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Aged Receivables Controller
 * Handles aged receivables report endpoints
 */
class AgedReceivablesController extends Controller
{
    public function __construct(
        private AgedReceivablesService $agedReceivablesService
    ) {}

    /**
     * Get aged receivables report
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Validate request parameters
            $validated = $request->validate([
                'search' => 'nullable|string|max:255',
                'min_amount' => 'nullable|numeric|min:0',
                'aging_bucket' => 'nullable|string|in:current,days_1_30,days_31_60,days_61_90,days_90_plus',
            ]);

            // Get report data
            $report = $this->agedReceivablesService->getAgedReceivables($validated);

            return response()->json([
                'data' => $report
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to generate aged receivables report',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get invoice details for a specific customer and aging bucket
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function details(Request $request): JsonResponse
    {
        try {
            // Validate request parameters
            $validated = $request->validate([
                'customer_id' => 'required|integer|exists:customers,id',
                'aging_bucket' => 'required|string|in:current,days_1_30,days_31_60,days_61_90,days_90_plus',
            ]);

            // Get invoice details
            $invoices = $this->agedReceivablesService->getInvoiceDetails(
                $validated['customer_id'],
                $validated['aging_bucket']
            );

            return response()->json([
                'data' => $invoices,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve invoice details',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
