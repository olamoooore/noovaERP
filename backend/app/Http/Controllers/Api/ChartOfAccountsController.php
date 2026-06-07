<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ChartOfAccountsController extends Controller
{
    /**
     * Get all accounts
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = ChartOfAccount::with(['parent', 'children']);

            // Filter by type with validation
            if ($request->has('type')) {
                $request->validate(['type' => 'string|in:asset,liability,equity,revenue,expense']);
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
     * Get account tree structure (parent-child hierarchy)
     */
    public function tree(): JsonResponse
    {
        try {
            // Load all accounts with their children
            $allAccounts = ChartOfAccount::with('children')
                ->orderBy('code')
                ->get();

            // Build a lookup map for quick access
            $accountMap = $allAccounts->keyBy('id');

            // Identify root accounts (no parent, or parent not in active set)
            $roots = $allAccounts->filter(function ($account) use ($accountMap) {
                if ($account->parent_id === null) {
                    return true;
                }
                // If parent is inactive or doesn't exist, treat as root
                return !isset($accountMap[$account->parent_id]);
            })->values();

            // Recursively build tree
            $buildTree = function ($accounts) use (&$buildTree) {
                return $accounts->map(function ($account) use ($buildTree) {
                    $node = [
                        'id' => $account->id,
                        'code' => $account->code,
                        'name' => $account->name,
                        'type' => $account->type,
                        'level' => $account->level,
                        'balance' => $account->getBalance(),
                        'is_active' => $account->is_active,
                        'is_system' => $account->is_system,
                    ];

                    if ($account->relationLoaded('children') && $account->children->isNotEmpty()) {
                        $node['children'] = $buildTree($account->children->sortBy('code')->values());
                    } else {
                        $node['children'] = [];
                    }

                    return $node;
                });
            };

            return response()->json([
                'data' => $buildTree($roots)
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to fetch account tree', ['error' => $e->getMessage()]);
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

            // System accounts: allow editing name/description/subtype/is_active but protect code & type
            if ($account->is_system) {
                $validated = $request->validate([
                    'name' => 'required|string|max:255',
                    'subtype' => 'nullable|string|max:50',
                    'description' => 'nullable|string',
                    'is_active' => 'boolean',
                ]);

                $account->update($validated);

                return response()->json([
                    'data' => $account,
                    'message' => 'System account updated successfully'
                ]);
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

            // Prevent setting self as parent
            if (!empty($validated['parent_id']) && (int) $validated['parent_id'] === (int) $id) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => ['parent_id' => ['An account cannot be its own parent.']]
                ], 422);
            }

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

    /**
     * Determine account level from SFRS code format.
     * L1: X000 (Parents)
     * L2: XX00 (Groups)
     * L3: XXXX (Individual accounts)
     */
    private function getAccountLevel(string $code): int
    {
        if (str_ends_with($code, '000')) {
            return 1;
        }
        if (str_ends_with($code, '00')) {
            return 2;
        }
        return 3;
    }
}
