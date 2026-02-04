<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ChartOfAccountsController extends Controller
{
    /**
     * Get all accounts
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // CRITICAL FIX: Ensure search_path is set for this connection
            $tenant = tenancy()->tenant;
            if ($tenant) {
                $schemaName = $tenant->getSchemaName();
                \Log::info("ChartOfAccountsController: Setting search_path to {$schemaName}");
                DB::statement("SET search_path TO \"{$schemaName}\", public");
            }

            $query = ChartOfAccount::with(['parent', 'children']);

            // Filter by type
            if ($request->has('type')) {
                $query->where('type', $request->type);
            }

            // Filter by active status
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            // Search
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('code', 'ILIKE', "%{$search}%")
                      ->orWhere('name', 'ILIKE', "%{$search}%");
                });
            }

            $accounts = $query->orderBy('code')->get();

            // Add balance to each account
            $accounts->each(function($account) {
                $account->balance = $account->getBalance();
            });

            return response()->json([
                'data' => $accounts
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch accounts',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get account tree structure (SFRS 3-level hierarchy)
     */
    public function tree(): JsonResponse
    {
        try {
            // CRITICAL FIX: Ensure search_path is set for this connection
            $tenant = tenancy()->tenant;
            if ($tenant) {
                $schemaName = $tenant->getSchemaName();
                DB::statement("SET search_path TO \"{$schemaName}\", public");
            }

            // Get Level 1 parents (X00000)
            $parents = ChartOfAccount::whereRaw("code LIKE '%00000'")
                ->orderBy('code')
                ->get();

            // Build hierarchy for each parent
            foreach ($parents as $parent) {
                $firstDigit = substr($parent->code, 0, 1);
                
                // Get Level 2 groups under this parent (XX0000 but not X00000)
                $groups = ChartOfAccount::whereRaw("code LIKE '{$firstDigit}_0000'")
                    ->whereRaw("code NOT LIKE '%00000'")
                    ->whereRaw("LENGTH(code) = 6")
                    ->orderBy('code')
                    ->get();
                
                // For each group, get Level 3 accounts
                foreach ($groups as $group) {
                    // Get accounts that belong to this group
                    $accounts = ChartOfAccount::where('account_group', $group->code)
                        ->whereRaw("code NOT LIKE '%0000'") // Exclude group headers
                        ->orderBy('code')
                        ->get();
                    
                    // Add balance to each account
                    $accounts->each(function($account) {
                        $account->balance = $account->getBalance();
                    });
                    
                    $group->children = $accounts;
                }
                
                $parent->children = $groups;
            }

            return response()->json([
                'data' => $parents
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch account tree',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single account
     */
    public function show(int $id): JsonResponse
    {
        try {
            // CRITICAL FIX: Ensure search_path is set for this connection
            $tenant = tenancy()->tenant;
            if ($tenant) {
                $schemaName = $tenant->getSchemaName();
                DB::statement("SET search_path TO \"{$schemaName}\", public");
            }

            $account = ChartOfAccount::with(['parent', 'children'])->findOrFail($id);
            $account->balance = $account->getBalance();

            return response()->json([
                'data' => $account
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Account not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Create new account
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'code' => 'required|string|max:20|unique:chart_of_accounts,code',
                'name' => 'required|string|max:255',
                'type' => 'required|in:asset,liability,equity,revenue,expense',
                'subtype' => 'nullable|string|max:50',
                'parent_id' => 'nullable|exists:chart_of_accounts,id',
                'description' => 'nullable|string',
                'is_active' => 'boolean',
                'opening_balance' => 'nullable|numeric',
            ]);

            $account = ChartOfAccount::create($validated);

            return response()->json([
                'data' => $account,
                'message' => 'Account created successfully'
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update account
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $account = ChartOfAccount::findOrFail($id);

            // Prevent editing system accounts
            if ($account->is_system) {
                return response()->json([
                    'message' => 'Cannot edit system accounts'
                ], 403);
            }

            $validated = $request->validate([
                'code' => 'required|string|max:20|unique:chart_of_accounts,code,' . $id,
                'name' => 'required|string|max:255',
                'type' => 'required|in:asset,liability,equity,revenue,expense',
                'subtype' => 'nullable|string|max:50',
                'parent_id' => 'nullable|exists:chart_of_accounts,id',
                'description' => 'nullable|string',
                'is_active' => 'boolean',
            ]);

            $account->update($validated);

            return response()->json([
                'data' => $account,
                'message' => 'Account updated successfully'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete account
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $account = ChartOfAccount::findOrFail($id);

            // Prevent deleting system accounts
            if ($account->is_system) {
                return response()->json([
                    'message' => 'Cannot delete system accounts'
                ], 403);
            }

            // Check if account has transactions
            if ($account->journalEntryLines()->exists()) {
                return response()->json([
                    'message' => 'Cannot delete account with transactions'
                ], 403);
            }

            // Check if account has children
            if ($account->children()->exists()) {
                return response()->json([
                    'message' => 'Cannot delete account with sub-accounts'
                ], 403);
            }

            $account->delete();

            return response()->json([
                'message' => 'Account deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete account',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
