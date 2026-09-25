<?php

namespace App\Http\Controllers\Api;

use App\Exports\FixedAssetsExport;
use App\Http\Controllers\Controller;
use App\Models\FixedAsset;
use App\Services\DepreciationService;
use App\Services\EntityAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Fixed Assets Controller
 * Manages fixed assets, depreciation, and disposal
 */
class FixedAssetsController extends Controller
{
    protected $depreciationService;

    protected EntityAccessService $entityAccessService;

    public function __construct(
        DepreciationService $depreciationService,
        EntityAccessService $entityAccessService,
    ) {
        $this->depreciationService = $depreciationService;
        $this->entityAccessService = $entityAccessService;
    }

    /**
     * Issue #163: scope an asset query to the requesting user's accessible legal
     * entities. Users with no entity grants keep the historical unscoped view
     * (same fail-open default as EnsureEntityAccess).
     */
    private function applyEntityScope(Request $request, $query)
    {
        $user = $request->user();

        if (! $user) {
            return $query;
        }

        $entityIds = $this->entityAccessService->accessibleEntityIds((int) $user->id);

        return $entityIds !== [] ? $query->accessibleBy($entityIds) : $query;
    }

    /**
     * Issue #163: find an asset that is inside the user's entity scope, or 404.
     */
    private function findScopedAsset(Request $request, int $id): FixedAsset
    {
        return $this->applyEntityScope($request, FixedAsset::query())->findOrFail($id);
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
            'department',
        ]);

        $this->applyEntityScope($request, $query);

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
            $query->where('location', 'LIKE', '%'.$request->location.'%');
        }

        // Filter by department
        if ($request->has('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('asset_number', 'LIKE', "%{$search}%")
                    ->orWhere('name', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        $assets = $query->orderBy('asset_number')->paginate($request->per_page ?? 15);

        return response()->json($assets);
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'columns' => ['required', 'array', 'min:1', 'max:'.count(FixedAssetsExport::COLUMNS)],
            'columns.*' => ['required', 'string', 'distinct', 'in:'.implode(',', array_keys(FixedAssetsExport::COLUMNS))],
            'format' => ['nullable', 'in:csv,xlsx'],
        ]);
        // Issue #233: export() was the one action #163 left unscoped — the CSV
        // leaked every entity's asset register to an entity-scoped user.
        $query = $this->applyEntityScope($request, FixedAsset::query());
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }
        if ($request->filled('search')) {
            $search = (string) $request->search;
            $query->where(fn ($q) => $q->where('asset_number', 'LIKE', "%{$search}%")->orWhere('name', 'LIKE', "%{$search}%")->orWhere('description', 'LIKE', "%{$search}%"));
        }

        $format = $validated['format'] ?? 'xlsx';

        return Excel::download(
            new FixedAssetsExport($query->orderBy('asset_number'), $validated['columns']),
            'fixed_assets_'.now()->format('Y-m-d').'.'.$format,
            $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX
        );
    }

    /**
     * Import fixed assets from a CSV file. Assets are created active, without
     * posting acquisition or depreciation journals; those remain controlled
     * follow-up workflows.
     */
    public function import(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:csv,txt|max:10240',
        ]);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $headers = fgetcsv($handle);
        if (! $headers) {
            fclose($handle);

            return response()->json(['message' => 'CSV file is empty.'], 422);
        }
        $headers = array_map(fn ($header) => strtolower(trim((string) $header)), $headers);
        $required = ['asset_number', 'name', 'category', 'purchase_date', 'purchase_cost', 'depreciation_method', 'depreciation_start_date', 'asset_account_code', 'depreciation_account_code', 'accumulated_depreciation_account_code'];
        $missing = array_values(array_diff($required, $headers));
        if ($missing) {
            fclose($handle);

            return response()->json(['message' => 'Missing required CSV columns: '.implode(', ', $missing)], 422);
        }

        $created = 0;
        $errors = [];
        $warnings = [];
        $rowNumber = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $row = array_pad(array_combine($headers, array_pad($values, count($headers), null)) ?: [], count($headers), null);
            try {
                $data = [
                    'asset_number' => trim((string) ($row['asset_number'] ?? '')),
                    'name' => trim((string) ($row['name'] ?? '')),
                    'category' => trim((string) ($row['category'] ?? '')),
                    'purchase_date' => $row['purchase_date'] ?? null,
                    'purchase_cost' => $row['purchase_cost'] ?? null,
                    'depreciation_method' => strtolower(trim((string) ($row['depreciation_method'] ?? ''))),
                    'useful_life_years' => $row['useful_life_years'] ?? null,
                    'useful_life_units' => $row['useful_life_units'] ?? null,
                    'salvage_value' => ($row['salvage_value'] ?? '') === '' ? 0 : $row['salvage_value'],
                    'depreciation_start_date' => $row['depreciation_start_date'] ?? null,
                    'location' => $row['location'] ?? null,
                    'department_id' => ($row['department_id'] ?? '') === '' ? null : $row['department_id'],
                    'responsible_person' => $row['responsible_person'] ?? null,
                    'serial_number' => $row['serial_number'] ?? null,
                    'description' => $row['description'] ?? null,
                ];
                $rowValidator = Validator::make($data, [
                    'asset_number' => 'required|string|max:50|unique:fixed_assets,asset_number',
                    'name' => 'required|string|max:255',
                    'category' => 'required|string|max:100',
                    'purchase_date' => 'required|date',
                    'purchase_cost' => 'required|numeric|min:0',
                    'depreciation_method' => 'required|in:straight_line,declining_balance,units_of_production',
                    'useful_life_years' => 'nullable|required_unless:depreciation_method,units_of_production|integer|min:1',
                    'useful_life_units' => 'nullable|required_if:depreciation_method,units_of_production|integer|min:1',
                    'salvage_value' => 'nullable|numeric|min:0',
                    'depreciation_start_date' => 'required|date',
                    'asset_account_code' => 'nullable',
                    'depreciation_account_code' => 'nullable',
                    'accumulated_depreciation_account_code' => 'nullable',
                    'department_id' => 'nullable|exists:departments,id',
                ]);
                if ($rowValidator->fails()) {
                    throw new \InvalidArgumentException(implode(' ', $rowValidator->errors()->all()));
                }
                foreach (['asset_account_code' => 'asset_account_id', 'depreciation_account_code' => 'depreciation_account_id', 'accumulated_depreciation_account_code' => 'accumulated_depreciation_account_id'] as $source => $target) {
                    $code = trim((string) ($row[$source] ?? ''));
                    $account = \App\Models\ChartOfAccount::where('code', $code)->first();
                    if (! $account) {
                        throw new \InvalidArgumentException("{$source} '{$code}' was not found.");
                    }
                    $data[$target] = $account->id;
                }
                $data['status'] = 'active';
                $data['is_active'] = true;
                $data['accumulated_depreciation'] = 0;
                $data['book_value'] = $data['purchase_cost'];
                // Issue #234: stamp the resolved entity/book context (same as
                // store()) so imported rows are visible to entity-scoped users —
                // a NULL legal_entity_id is invisible to whereIn().
                $data['legal_entity_id'] = $request->attributes->get('legal_entity_id');
                $data['entity_book_id'] = $request->attributes->get('entity_book_id');
                FixedAsset::create($data);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = ['row' => $rowNumber, 'message' => $e->getMessage()];
            }
        }
        fclose($handle);

        return response()->json([
            'created' => $created,
            'warnings' => $warnings,
            'errors' => $errors,
        ], $created > 0 || ! $errors ? 200 : 422);
    }

    /**
     * Get asset details with depreciation schedule
     */
    public function show(Request $request, $id)
    {
        $asset = $this->applyEntityScope(
            $request,
            FixedAsset::with([
                'assetAccount',
                'depreciationAccount',
                'accumulatedDepreciationAccount',
                'vendor',
                'department',
                'depreciationSchedules' => function ($query) {
                    $query->orderBy('period_date', 'desc');
                },
            ])
        )->findOrFail($id);

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
            // Issue #163: stamp the resolved entity/book context (JournalEntry pattern).
            $data['legal_entity_id'] = $request->attributes->get('legal_entity_id');
            $data['entity_book_id'] = $request->attributes->get('entity_book_id');

            $asset = FixedAsset::create($data);

            DB::commit();

            return response()->json([
                'message' => 'Fixed asset created successfully',
                'id' => $asset->id,
                'asset' => $asset->load([
                    'assetAccount',
                    'depreciationAccount',
                    'accumulatedDepreciationAccount',
                ]),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['error' => 'Failed to create asset: '.$e->getMessage()], 500);
        }
    }

    /**
     * Update fixed asset
     */
    public function update(Request $request, $id)
    {
        $asset = $this->findScopedAsset($request, (int) $id);

        // Prevent editing disposed assets
        if ($asset->status === 'disposed') {
            return response()->json(['error' => 'Cannot edit disposed asset'], 422);
        }

        $validator = Validator::make($request->all(), [
            'asset_number' => 'sometimes|string|max:50|unique:fixed_assets,asset_number,'.$id,
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
            'department_id', 'responsible_person', 'serial_number',
        ];

        $updateData = $request->only($allowedFields);
        $asset->update($updateData);

        return response()->json([
            'message' => 'Asset updated successfully',
            'asset' => $asset->fresh([
                'assetAccount',
                'depreciationAccount',
                'accumulatedDepreciationAccount',
            ]),
        ]);
    }

    /**
     * Deactivate asset (soft delete)
     */
    public function destroy(Request $request, $id)
    {
        $asset = $this->findScopedAsset($request, (int) $id);

        // Check if asset has depreciation entries
        if ($asset->depreciationSchedules()->where('is_posted', true)->exists()) {
            return response()->json([
                'error' => 'Cannot delete asset with posted depreciation entries',
            ], 422);
        }

        $asset->update(['is_active' => false]);

        return response()->json([
            'message' => 'Asset deactivated successfully',
        ]);
    }

    /**
     * Dispose asset
     */
    /**
     * Issue #94: impair a fixed asset — reduce its carrying amount to the
     * recoverable amount and post the loss (Dr depreciation expense /
     * Cr accumulated depreciation) through PostingEngine.
     */
    public function impair(Request $request, int $id)
    {
        $asset = $this->findScopedAsset($request, $id);

        $validated = $request->validate([
            'impairment_date' => 'required|date',
            'recoverable_amount' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:500',
        ]);

        // Hostile re-review C-2 (#94): disposed assets cannot be impaired,
        // and the asset must be row-locked while its carrying amount is read
        // and written so concurrent impairments cannot double-count.
        if ($asset->status === 'disposed') {
            return response()->json([
                'message' => 'A disposed asset cannot be impaired.',
            ], 422);
        }

        $carrying = (float) $asset->book_value;
        $recoverable = round((float) $validated['recoverable_amount'], 2);
        $loss = round($carrying - $recoverable, 2);

        if ($loss <= 0) {
            return response()->json([
                'message' => 'No impairment required: the recoverable amount is not below the carrying amount.',
            ], 422);
        }

        if (! $asset->depreciation_account_id || ! $asset->accumulated_depreciation_account_id) {
            return response()->json([
                'message' => 'Asset is missing its depreciation/accumulated accounts — cannot post the impairment.',
            ], 422);
        }

        return DB::transaction(function () use ($asset, $validated, $carrying, $recoverable, $loss) {
            // Re-read under a row lock so a concurrent impairment cannot use
            // the same carrying amount (lost update on book_value).
            $asset = \App\Models\FixedAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();

            // Hostile re-review (#94): the posting idempotency guard keys on
            // (documentType, documentId). Using the asset id made every repeat
            // impairment resolve to the FIRST journal entry, so the GL only
            // ever carried one loss while the subledger accumulated each one.
            // Create the record first and key the posting on it — one JE per
            // impairment event.
            $record = \App\Models\ImpairmentRecord::create([
                'tenant_id' => tenant()->id,
                'fixed_asset_id' => $asset->id,
                'impairment_date' => $validated['impairment_date'],
                'carrying_amount_before' => $carrying,
                'recoverable_amount' => $recoverable,
                'impairment_loss' => $loss,
                'reason' => $validated['reason'] ?? null,
                'journal_entry_id' => null,
                'created_by' => \Modules\OCRBookkeeping\Helpers\TenantUserHelper::resolveTenantUserId(),
            ]);

            $result = app(\App\Accounting\PostingEngine::class)->post(new \App\Accounting\DTOs\PostingRequest(
                documentType: 'asset_impairment',
                documentId: (int) $record->id,
                postingDate: $validated['impairment_date'],
                description: "Impairment - {$asset->name}",
                lines: [
                    new \App\Accounting\DTOs\PostingRequestLine(
                        accountMappingKey: 'depreciation_expense',
                        accountId: (int) $asset->depreciation_account_id,
                        debit: $loss,
                        credit: 0.0,
                        description: "Impairment loss - {$asset->name}",
                    ),
                    new \App\Accounting\DTOs\PostingRequestLine(
                        accountMappingKey: 'accumulated_depreciation',
                        accountId: (int) $asset->accumulated_depreciation_account_id,
                        debit: 0.0,
                        credit: $loss,
                        description: "Accumulated impairment - {$asset->name}",
                    ),
                ],
                reference: $asset->asset_number,
                userId: \Modules\OCRBookkeeping\Helpers\TenantUserHelper::requireTenantUserId(),
            ));

            if (! $result->success) {
                throw new \RuntimeException('Impairment posting failed: '.($result->error ?? 'unknown error'));
            }

            $record->update([
                'journal_entry_id' => $result->journalEntryId ?? ($result->journalEntry?->id ?? null),
            ]);

            $asset->update([
                'book_value' => $recoverable,
                'accumulated_depreciation' => (float) $asset->accumulated_depreciation + $loss,
            ]);

            return response()->json([
                'message' => 'Asset impaired',
                'impairment' => $record,
                'asset' => $asset->fresh(),
            ], 201);
        });
    }

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

        $asset = $this->findScopedAsset($request, (int) $id);

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
                'journal_entry' => $result['journal_entry'],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['error' => 'Failed to dispose asset: '.$e->getMessage()], 500);
        }
    }

    /**
     * Get depreciation schedule for an asset
     */
    public function getDepreciationSchedule(Request $request, $id)
    {
        $asset = $this->applyEntityScope(
            $request,
            FixedAsset::with([
                'depreciationSchedules' => function ($query) {
                    $query->orderBy('period_date', 'asc');
                },
            ])
        )->findOrFail($id);

        $schedule = $asset->depreciationSchedules->map(function ($entry) {
            return array_merge($entry->toArray(), [
                'beginning_value' => (float) $entry->book_value + (float) $entry->depreciation_amount,
            ]);
        });

        return response()->json([
            'asset' => $asset,
            'schedule' => $schedule,
        ]);
    }

    /**
     * Generate the full multi-period depreciation schedule for one asset
     * (POST /api/v1/assets/{id}/generate-schedule).
     *
     * For a straight-line, 3-year, monthly asset this creates 36
     * depreciation_schedule rows + 36 journal entries, one per period
     * starting from asset.depreciation_start_date. Idempotent: existing
     * period entries are skipped, not duplicated.
     *
     * When a period has no fiscal period open, the schedule row is created
     * (is_posted=false) and the error is reported in the response so the
     * user can call postPendingSchedules once the fiscal period is opened.
     */
    public function generateSchedule(Request $request, $id)
    {
        // Issue #163: cross-entity generation must 404, not proceed. Resolve
        // the asset OUTSIDE the try so ModelNotFoundException becomes a 404
        // rather than being swallowed into a 500.
        $this->findScopedAsset($request, (int) $id);

        try {
            $result = $this->depreciationService->generateScheduleForAsset((int) $id);

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => sprintf(
                    'Generated %d depreciation entries (skipped %d existing, %d pending fiscal period, stopped at salvage for %d).',
                    $result['generated'],
                    $result['skipped_existing'],
                    $result['pending_posting'] ?? 0,
                    $result['stopped_salvage']
                ),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate schedule',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Post all schedule rows that exist but have no journal entry yet
     * (e.g. created by generateScheduleForAsset before the fiscal period
     * was opened). Call this after creating the missing fiscal periods.
     */
    public function postPendingSchedules(Request $request, $id)
    {
        // Issue #163: cross-entity posting must 404, not proceed. Resolve
        // the asset OUTSIDE the try so ModelNotFoundException becomes a 404
        // rather than being swallowed into a 500.
        $this->findScopedAsset($request, (int) $id);

        try {
            $result = $this->depreciationService->postPendingScheduleEntries((int) $id);

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => sprintf(
                    'Posted %d pending schedule entries (%d failed, %d skipped).',
                    $result['posted'],
                    count($result['errors']),
                    $result['skipped_no_entry'] ?? 0
                ),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to post pending schedules',
                'error' => $e->getMessage(),
            ], 500);
        }
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

        // Issue #163: a scoped user runs depreciation only for assets inside
        // their entity access; requesting an out-of-scope asset is rejected.
        $user = $request->user();
        $accessibleEntityIds = $user
            ? $this->entityAccessService->accessibleEntityIds((int) $user->id)
            : [];
        $assetIds = $request->asset_ids;

        if ($accessibleEntityIds !== []) {
            if (empty($assetIds)) {
                // No explicit selection: restrict "all active assets" to scope.
                $assetIds = $this->applyEntityScope($request, FixedAsset::query())->pluck('id')->all();
            } else {
                $allowedIds = $this->applyEntityScope($request, FixedAsset::whereIn('id', $assetIds))->pluck('id')->all();

                if (count($allowedIds) !== count(array_unique($assetIds))) {
                    return response()->json([
                        'error' => 'One or more assets are outside your entity access.',
                    ], 403);
                }

                $assetIds = $allowedIds;
            }
        }

        DB::beginTransaction();
        try {
            $periodDate = $request->period_date;

            $results = $this->depreciationService->calculateDepreciationForPeriod(
                $periodDate,
                $assetIds
            );

            DB::commit();

            // Determine an accurate response message based on results
            $successCount = collect($results)->where('status', 'success')->count();
            $errorCount = collect($results)->where('status', 'error')->count();
            $skippedCount = collect($results)->where('status', 'skipped')->count();
            $pendingCount = (int) collect($results)->sum('pending_posting');
            $totalCount = count($results);

            if ($totalCount === 0) {
                $message = 'No active assets found for the selected period.';
            } elseif ($successCount === $totalCount && $pendingCount > 0) {
                $message = "Depreciation schedule generated for {$successCount} asset(s). {$pendingCount} period(s) have no journal entry yet (period closed or not open) and can be posted once the period is open.";
            } elseif ($successCount === $totalCount) {
                $message = 'Depreciation calculated successfully.';
            } elseif ($successCount > 0) {
                $message = "Depreciation calculated for {$successCount} asset(s). {$skippedCount} skipped, {$errorCount} error(s).";
                if ($pendingCount > 0) {
                    $message .= " {$pendingCount} period(s) pending posting.";
                }
            } elseif ($errorCount > 0) {
                $message = 'Depreciation calculation failed. Check results for details.';
            } else {
                $message = 'No depreciation was needed for the selected assets in this period.';
            }

            return response()->json([
                'success' => $successCount > 0,
                'message' => $message,
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Depreciation calculation failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to calculate depreciation: '.$e->getMessage(),
            ], 500);
        }
    }
}
