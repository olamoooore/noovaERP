<?php

namespace Modules\FinancialReporting\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinancialReporting\Services\Analytics\SalesRecordsService;

/**
 * #1020: the client's Sales Records report (one row per invoice, Qty/S$ column
 * pairs for the selected grouping) — By item code, brand, supplier, customer
 * or category, with the sheet's monthly totals.
 */
class SalesRecordsController extends Controller
{
    public function __construct(protected SalesRecordsService $service) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'group_by' => 'nullable|in:item_code,brand,supplier,customer,category',
            'brand_id' => 'nullable|integer|exists:brands,id',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'category_id' => 'nullable|integer|exists:product_categories,id',
            'product_id' => 'nullable|integer|exists:products,id',
            'item_code' => 'nullable|string|max:100',
        ]);

        try {
            return response()->json([
                'success' => true,
                'data' => $this->service->generate($validated),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            \Log::error('Sales Records report generation failed', [
                'error' => $e->getMessage(),
                'filters' => $validated,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate report. Please try again.',
            ], 500);
        }
    }
}
