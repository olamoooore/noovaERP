<?php

namespace App\Http\Controllers\Api;

use App\Accounting\Services\FxRevaluationService;
use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use Modules\FinancialReporting\Services\Reports\TrialBalanceService;
use Modules\FinancialReporting\Services\Reports\GeneralLedgerService;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountingController extends Controller
{
    public function __construct(
        private TrialBalanceService $trialBalanceService,
        private GeneralLedgerService $generalLedgerService,
    ) {}

    // ==================== Chart of Accounts CRUD ====================

    /**
     * Get all chart of accounts (with optional filters)
     */
    public function chartOfAccounts(Request $request)
    {
        $query = ChartOfAccount::query();

        // Filter by active status
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Search by code or name
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        // Include parent relationship
        $query->with('parent');

        $accounts = $query->orderBy('code')->get()->map(function ($account) {
            return [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'subtype' => $account->subtype,
                'parent_id' => $account->parent_id,
                'parent_name' => $account->parent?->name,
                'balance' => $account->getBalance(),
                'is_active' => $account->is_active,
                'is_system' => $account->is_system,
                'description' => $account->description,
            ];
        });

        return response()->json($accounts);
    }

    /**
     * Get a single chart of account
     */
    public function getAccount($id)
    {
        $account = ChartOfAccount::with('parent', 'children')->findOrFail($id);

        return response()->json([
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type,
            'subtype' => $account->subtype,
            'parent_id' => $account->parent_id,
            'parent' => $account->parent,
            'children' => $account->children,
            'balance' => $account->getBalance(),
            'is_active' => $account->is_active,
            'is_system' => $account->is_system,
            'description' => $account->description,
            'opening_balance' => $account->opening_balance,
        ]);
    }

    /**
     * Create a new chart of account
     */
    public function createAccount(Request $request)
    {
        $validated = $request->validate([
            'code' => 'required|string|max:20|unique:chart_of_accounts,code',
            'name' => 'required|string|max:255',
            'type' => 'required|in:asset,liability,equity,revenue,expense',
            'subtype' => 'nullable|string|max:50',
            'parent_id' => 'nullable|exists:chart_of_accounts,id',
            'description' => 'nullable|string',
            'opening_balance' => 'nullable|numeric',
        ]);

        $account = ChartOfAccount::create($validated);

        return response()->json([
            'message' => 'Account created successfully',
            'account' => $account,
        ], 201);
    }

    /**
     * Update a chart of account
     */
    public function updateAccount(Request $request, $id)
    {
        $account = ChartOfAccount::findOrFail($id);

        // Check if account is system account
        if ($account->is_system) {
            return response()->json([
                'message' => 'Cannot modify system accounts',
            ], 403);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('chart_of_accounts')->ignore($id)],
            'name' => 'required|string|max:255',
            'type' => 'required|in:asset,liability,equity,revenue,expense',
            'subtype' => 'nullable|string|max:50',
            'parent_id' => 'nullable|exists:chart_of_accounts,id',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        // Prevent type change if transactions exist
        if (isset($validated['type']) && $validated['type'] !== $account->type) {
            $hasTransactions = $account->journalEntryLines()->exists();
            if ($hasTransactions) {
                return response()->json([
                    'message' => 'Cannot change account type when transactions exist',
                ], 422);
            }
        }

        // Prevent deactivation if balance is non-zero
        if (isset($validated['is_active']) && !$validated['is_active']) {
            $balance = $account->getBalance();
            if (abs($balance) > 0.01) {
                return response()->json([
                    'message' => 'Cannot deactivate account with non-zero balance',
                    'balance' => $balance,
                ], 422);
            }
        }

        $account->update($validated);

        return response()->json([
            'message' => 'Account updated successfully',
            'account' => $account,
        ]);
    }

    /**
     * Delete (deactivate) a chart of account
     */
    public function deleteAccount($id)
    {
        $account = ChartOfAccount::findOrFail($id);

        // Check if account is system account
        if ($account->is_system) {
            return response()->json([
                'message' => 'Cannot delete system accounts',
            ], 403);
        }

        // Check if balance is zero
        $balance = $account->getBalance();
        if (abs($balance) > 0.01) {
            return response()->json([
                'message' => 'Cannot delete account with non-zero balance',
                'balance' => $balance,
            ], 422);
        }

        // Check if has children
        if ($account->children()->exists()) {
            return response()->json([
                'message' => 'Cannot delete account with child accounts',
            ], 422);
        }

        // Soft delete or deactivate
        $account->update(['is_active' => false]);

        return response()->json([
            'message' => 'Account deactivated successfully',
        ]);
    }

    /**
     * Get account hierarchy tree
     */
    public function accountHierarchy()
    {
        $accounts = ChartOfAccount::with('children')
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $buildTree = function ($accounts) use (&$buildTree) {
            return $accounts->map(function ($account) use ($buildTree) {
                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'balance' => $account->getBalance(),
                    'children' => $buildTree($account->children),
                ];
            });
        };

        return response()->json($buildTree($accounts));
    }

    /**
     * Get account balance with date range
     */
    public function accountBalance($id, Request $request)
    {
        $account = ChartOfAccount::findOrFail($id);

        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        $query = $account->journalEntryLines()
            ->whereHas('journalEntry', function ($q) use ($startDate, $endDate) {
                $q->where('status', 'posted');
                if ($startDate) {
                    $q->where('entry_date', '>=', $startDate);
                }
                if ($endDate) {
                    $q->where('entry_date', '<=', $endDate);
                }
            });

        $totals = $query->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')
            ->first();

        $debit = (float) ($totals->total_debit ?? 0);
        $credit = (float) ($totals->total_credit ?? 0);

        // Calculate balance based on account type
        if (in_array($account->type, ['asset', 'expense'])) {
            $balance = $debit - $credit;
        } else {
            $balance = $credit - $debit;
        }

        return response()->json([
            'account' => [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
            ],
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'totals' => [
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $balance,
            ],
        ]);
    }

    // ==================== Journal Entries ====================

    public function journalEntries()
    {
        $entries = JournalEntry::with(['lines.account', 'createdBy'])
            ->where('status', 'posted')
            ->orderBy('entry_date', 'desc')
            ->paginate(50);

        return response()->json($entries);
    }

    // ==================== Reports ====================

    public function trialBalance()
    {
        $filter = new ReportFilter(asOfDate: now());
        $reportData = $this->trialBalanceService->generate($filter);

        return response()->json([
            'accounts' => $reportData->data,
            'total_debit' => $reportData->summary['total_debits'],
            'total_credit' => $reportData->summary['total_credits'],
            'is_balanced' => $reportData->summary['is_balanced'],
        ]);
    }

    public function ledger($accountId)
    {
        try {
            $filter = new ReportFilter(
                startDate: now()->startOfYear(),
                endDate: now(),
                accountId: (int) $accountId,
            );
            $reportData = $this->generalLedgerService->generate($filter);

            return response()->json([
                'account' => $reportData->data['account'] ?? null,
                'opening_balance' => $reportData->data['opening_balance'] ?? 0,
                'transactions' => $reportData->data['transactions'] ?? [],
                'closing_balance' => $reportData->data['closing_balance'] ?? 0,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve ledger',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ==================== Period-End Processes ====================

    public function fxRevaluation(Request $request, FxRevaluationService $fxService)
    {
        $validated = $request->validate([
            'revaluation_date' => 'required|date',
            'base_currency'    => 'required|string|size:3',
            'account_ids'      => 'nullable|array',
            'account_ids.*'    => 'integer|exists:chart_of_accounts,id',
        ]);

        $results = $fxService->revalue(
            $validated['revaluation_date'],
            strtoupper($validated['base_currency']),
            $validated['account_ids'] ?? null,
        );

        $posted = collect($results)->filter(fn ($r) => $r->success);
        $failed = collect($results)->filter(fn ($r) => !$r->success);

        return response()->json([
            'message'        => $posted->count() . ' revaluation entries posted',
            'posted_entries' => $posted->map(fn ($r) => $r->journalEntryId)->values(),
            'failures'       => $failed->map(fn ($r) => $r->error)->values(),
        ]);
    }
}
