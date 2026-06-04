<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FixedAsset;
use App\Services\DepreciationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Fixed Assets Controller
 * Manages fixed assets, depreciation, and disposal
 */
class FixedAssetsController extends Controller
{
    protected $depreciationService;

    public function __construct(DepreciationService $depreciationService)
    {
        $this->depreciationService = $depreciationService;
    }

    /**
     * List all fixed assets
     */
    public function index(Request $request)
    {
        $query = FixedAsset::with([
            'assetAccount',
            'depreciationAccount',
            'accumulatedDepreciationAccount',
            'vendor',
            'department'
        ]);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by category
        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        // Filter by location
        if ($request->has('location')) {
            $query->where('location', 'LIKE', '%' . $request->location . '%');
        }

        // Filter by department
        if ($request->has('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('asset_number', 'LIKE', "%{$search}%")
                  ->orWhere('name', 'LIKE', "%{$search}%")
                  ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        $assets = $query->orderBy('asset_number')->paginate($request->per_page ?? 15);

        return response()->json($assets);
    }

    /**
     * Get asset details with depreciation schedule
     */
    public function show($id)
    {
        $asset = FixedAsset::with([
            'assetAccount',
            'depreciationAccount',
            'accumulatedDepreciationAccount',
            'vendor',
            'department',
            'depreciationSchedules' => function($query) {
                $query->orderBy('period_date', 'desc');
            }
        ])->findOrFail($id);

        return response()->json($asset);
    }

    /**
     * Create new fixed asset
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'asset_number' => 'required|string|max:50|unique:fixed_assets,asset_number',
            'name' => 'required|string|max:255',
            'category' => 'required|string|max:100',
            'purchase_date' => 'required|date',
            'purchase_cost' => 'required|numeric|min:0',
            'depreciation_method' => 'required|in:straight_line,declining_balance,units_of_production',
            'useful_life_years' => 'required_unless:depreciation_method,units_of_production|integer|min:1',
            'useful_life_units' => 'required_if:depreciation_method,units_of_production|integer|min:1',
            'salvage_value' => 'nullable|numeric|min:0',
            'depreciation_start_date' => 'required|date',
            'asset_account_id' => 'required|exists:chart_of_accounts,id',
            'depreciation_account_id' => 'required|exists:chart_of_accounts,id',
            'accumulated_depreciation_account_id' => 'required|exists:chart_of_accounts,id',
            'location' => 'nullable|string|max:255',
            'department_id' => 'nullable|exists:departments,id',
            'responsible_person' => 'nullable|string|max:255',
            'serial_number' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $data = $request->all();
            $data['status'] = 'active';
            $data['is_active'] = true;
            $data['accumulated_depreciation'] = 0;
            $data['book_value'] = $data['purchase_cost'];

            $asset = FixedAsset::create($data);

            DB::commit();

            return response()->json([
                'message' => 'Fixed asset created successfully',
                'id' => $asset->id,
                'asset' => $asset->load([
                    'assetAccount',
                    'depreciationAccount',
                    'accumulatedDepreciationAccount'
                ])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to create asset: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update fixed asset
     */
    public function update(Request $request, $id)
    {
        $asset = FixedAsset::findOrFail($id);

        // Prevent editing disposed assets
        if ($asset->status === 'disposed') {
            return response()->json(['error' => 'Cannot edit disposed asset'], 422);
        }

        $validator = Validator::make($request->all(), [
            'asset_number' => 'sometimes|string|max:50|unique:fixed_assets,asset_number,' . $id,
            'name' => 'sometimes|string|max:255',
            'category' => 'sometimes|string|max:100',
            'location' => 'nullable|string|max:255',
            'department_id' => 'nullable|exists:departments,id',
            'responsible_person' => 'nullable|string|max:255',
            'serial_number' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Only allow updating certain fields after creation
        $allowedFields = [
            'name', 'description', 'category', 'location',
            'department_id', 'responsible_person', 'serial_number'
        ];

        $updateData = $request->only($allowedFields);
        $asset->update($updateData);

        return response()->json([
            'message' => 'Asset updated successfully',
            'asset' => $asset->fresh([
                'assetAccount',
                'depreciationAccount',
                'accumulatedDepreciationAccount'
            ])
        ]);
    }

    /**
     * Deactivate asset (soft delete)
     */
    public function destroy($id)
    {
        $asset = FixedAsset::findOrFail($id);

        // Check if asset has depreciation entries
        if ($asset->depreciationSchedules()->where('is_posted', true)->exists()) {
            return response()->json([
                'error' => 'Cannot delete asset with posted depreciation entries'
            ], 422);
        }

        $asset->update(['is_active' => false]);

        return response()->json([
            'message' => 'Asset deactivated successfully'
        ]);
    }

    /**
     * Dispose asset
     */
    public function dispose(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'disposal_date' => 'required|date',
            'disposal_amount' => 'required|numeric|min:0',
            'disposal_method' => 'required|in:sold,scrapped,donated',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $asset = FixedAsset::findOrFail($id);

        if ($asset->status === 'disposed') {
            return response()->json(['error' => 'Asset already disposed'], 422);
        }

        DB::beginTransaction();
        try {
            // Calculate gain/loss and create journal entry
            $result = $this->depreciationService->disposeAsset(
                $asset,
                $request->disposal_date,
                $request->disposal_amount,
                $request->disposal_method,
                $request->notes
            );

            DB::commit();

            return response()->json([
                'message' => 'Asset disposed successfully',
                'asset' => $asset->fresh(),
                'gain_loss' => $result['gain_loss'],
                'journal_entry' => $result['journal_entry']
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to dispose asset: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get depreciation schedule for an asset
     */
    public function getDepreciationSchedule($id)
    {
        $asset = FixedAsset::with([
            'depreciationSchedules' => function($query) {
                $query->orderBy('period_date', 'asc');
            }
        ])->findOrFail($id);

        $schedule = $asset->depreciationSchedules->map(function ($entry) {
            return array_merge($entry->toArray(), [
                'beginning_value' => (float) $entry->book_value + (float) $entry->depreciation_amount,
            ]);
        });

        return response()->json([
            'asset' => $asset,
            'schedule' => $schedule
        ]);
    }

    /**
     * Calculate and run depreciation for a period
     */
    public function calculateDepreciation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'period_date' => 'required|date',
            'asset_ids' => 'nullable|array',
            'asset_ids.*' => 'exists:fixed_assets,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $periodDate = $request->period_date;
            $assetIds = $request->asset_ids;

            $results = $this->depreciationService->calculateDepreciationForPeriod(
                $periodDate,
                $assetIds
            );

            DB::commit();

            // Determine an accurate response message based on results
            $successCount = collect($results)->where('status', 'success')->count();
            $errorCount = collect($results)->where('status', 'error')->count();
            $skippedCount = collect($results)->where('status', 'skipped')->count();
            $totalCount = count($results);

            if ($totalCount === 0) {
                $message = 'No active assets found for the selected period.';
            } elseif ($successCount === $totalCount) {
                $message = 'Depreciation calculated successfully.';
            } elseif ($successCount > 0) {
                $message = "Depreciation calculated for {$successCount} asset(s). {$skippedCount} skipped, {$errorCount} error(s).";
            } elseif ($errorCount > 0) {
                $message = 'Depreciation calculation failed. Check results for details.';
            } else {
                $message = 'No depreciation was needed for the selected assets in this period.';
            }

            return response()->json([
                'success' => $successCount > 0,
                'message' => $message,
                'results' => $results
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Depreciation calculation failed', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => 'Failed to calculate depreciation: ' . $e->getMessage()
            ], 500);
        }
    }
}
