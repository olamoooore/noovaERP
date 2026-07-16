<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticAccount;
use App\Services\AnalyticAccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Analytic Accounts Controller
 * Manages cost centers, projects, departments, and locations
 */
class AnalyticAccountsController extends Controller
{
    protected $analyticAccountService;

    public function __construct(AnalyticAccountService $analyticAccountService)
    {
        $this->analyticAccountService = $analyticAccountService;
    }

    /**
     * List all analytic accounts
     */
    public function index(Request $request)
    {
        $query = AnalyticAccount::with(['parent', 'manager']);

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        // Search by code or name
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'LIKE', "%{$search}%")
                    ->orWhere('name', 'LIKE', "%{$search}%");
            });
        }

        $accounts = $query->orderBy('code')->get();

        return response()->json($accounts);
    }

    /**
     * Get analytic account hierarchy tree
     */
    public function tree(Request $request)
    {
        $type = $request->input('type');

        $query = AnalyticAccount::with(['children' => function ($q) {
            $q->with('children')->orderBy('code');
        }, 'manager'])->whereNull('parent_id');

        if ($type) {
            $query->where('type', $type);
        }

        $tree = $query->orderBy('code')->get();

        return response()->json($tree);
    }

    /**
     * Get a specific analytic account
     */
    public function show($id)
    {
        $account = AnalyticAccount::with(['parent', 'children', 'manager'])->findOrFail($id);

        return response()->json($account);
    }

    /**
     * Create a new analytic account
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:20|unique:analytic_accounts,code',
            'name' => 'required|string|max:255',
            'type' => 'required|in:department,project,location,product_line',
            'parent_id' => 'nullable|exists:analytic_accounts,id',
            'manager_id' => 'nullable|exists:users,id',
            'budget_amount' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $account = AnalyticAccount::create([
            'code' => $request->code,
            'name' => $request->name,
            'type' => $request->type,
            'parent_id' => $request->parent_id,
            'manager_id' => $request->manager_id,
            'budget_amount' => $request->budget_amount,
            'description' => $request->description,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Analytic account created successfully',
            'account' => $account->load(['parent', 'manager']),
        ], 201);
    }

    /**
     * Update an analytic account
     */
    public function update(Request $request, $id)
    {
        $account = AnalyticAccount::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'code' => 'sometimes|string|max:20|unique:analytic_accounts,code,'.$id,
            'name' => 'sometimes|string|max:255',
            'type' => 'sometimes|in:department,project,location,product_line',
            'parent_id' => 'nullable|exists:analytic_accounts,id',
            'manager_id' => 'nullable|exists:users,id',
            'budget_amount' => 'nullable|numeric|min:0',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Prevent circular parent reference
        if ($request->has('parent_id') && $request->parent_id) {
            if ($request->parent_id == $id) {
                return response()->json(['error' => 'Account cannot be its own parent'], 422);
            }
            // Check if new parent is a descendant
            $parent = AnalyticAccount::find($request->parent_id);
            if ($parent && $this->isDescendant($account, $parent)) {
                return response()->json(['error' => 'Cannot set a descendant as parent'], 422);
            }
        }

        $account->update($request->only([
            'code', 'name', 'type', 'parent_id', 'manager_id',
            'budget_amount', 'description', 'is_active',
        ]));

        return response()->json([
            'message' => 'Analytic account updated successfully',
            'account' => $account->fresh(['parent', 'manager']),
        ]);
    }

    /**
     * Delete (deactivate) an analytic account
     */
    public function destroy($id)
    {
        $account = AnalyticAccount::findOrFail($id);

        // Check if account has children
        if ($account->children()->count() > 0) {
            return response()->json([
                'error' => 'Cannot delete account with child accounts',
            ], 422);
        }

        $account->update(['is_active' => false]);

        return response()->json([
            'message' => 'Analytic account deactivated successfully',
        ]);
    }

    /**
     * Get balance for an analytic account
     */
    public function balance(Request $request, $id)
    {
        $account = AnalyticAccount::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'dimension' => 'required|in:department,project,location',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'include_children' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $includeChildren = $request->input('include_children', false);

        if ($includeChildren) {
            $balance = $this->analyticAccountService->getBalanceWithChildren(
                $account,
                $request->dimension,
                $request->start_date,
                $request->end_date
            );
        } else {
            $balance = $account->getBalance(
                $request->dimension,
                $request->start_date,
                $request->end_date
            );
        }

        return response()->json([
            'account' => $account,
            'balance' => $balance,
            'dimension' => $request->dimension,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'include_children' => $includeChildren,
        ]);
    }

    /**
     * Get P&L report for an analytic account
     */
    public function profitLoss(Request $request, $id)
    {
        $account = AnalyticAccount::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'dimension' => 'required|in:department,project,location',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $report = $this->analyticAccountService->getProfitLoss(
            $account,
            $request->dimension,
            $request->start_date,
            $request->end_date
        );

        return response()->json($report);
    }

    /**
     * Get report by dimension (all accounts of a type)
     */
    public function reportByDimension(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'dimension' => 'required|in:department,project,location',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $report = $this->analyticAccountService->getReportByDimension(
            $request->dimension,
            $request->start_date,
            $request->end_date
        );

        return response()->json($report);
    }

    /**
     * Check if an account is a descendant of another
     */
    protected function isDescendant(AnalyticAccount $account, AnalyticAccount $potentialAncestor): bool
    {
        $current = $account;
        while ($current->parent_id) {
            if ($current->parent_id == $potentialAncestor->id) {
                return true;
            }
            $current = $current->parent;
        }

        return false;
    }
}
