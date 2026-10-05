<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Platform\Import\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChartOfAccountsController extends Controller
{
    public function __construct(private readonly ImportService $importService) {}

    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate(['file' => 'required|file|mimes:csv,txt|max:10240']);

        $result = $this->importService->importCSV('chart_of_accounts', $validated['file'], [
            'code' => 'code', 'account_code' => 'code', 'name' => 'name', 'account_name' => 'name',
            'type' => 'type', 'subtype' => 'subtype', 'description' => 'description',
            'is_active' => 'is_active', 'opening_balance' => 'opening_balance',
        ], [
            'upsert' => true,
            'upsert_key' => 'code',
            'rules' => [
                'code' => ['required' => null, 'max' => 20],
                'name' => ['required' => null, 'max' => 255],
                'type' => ['required' => null, 'in' => ['asset', 'liability', 'equity', 'revenue', 'expense']],
            ],
            'transform' => function (array $record): array {
                $record['code'] = trim((string) ($record['code'] ?? ''));
                $record['name'] = trim((string) ($record['name'] ?? ''));
                $record['type'] = strtolower(trim((string) ($record['type'] ?? '')));
                $record['is_active'] = filter_var($record['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
                $record['opening_balance'] = is_numeric($record['opening_balance'] ?? null) ? $record['opening_balance'] : 0;
                $record['updated_at'] = now();
                if (! ChartOfAccount::where('code', $record['code'])->exists()) $record['created_at'] = now();

                return $record;
            },
        ]);

        $result['errors'] = array_merge($result['errors'], $this->applyImportedParents($validated['file']));

        return response()->json($result + [
            'count' => $result['created'] + $result['updated'],
            'message' => 'Chart of Accounts import completed.',
        ]);
    }

    /** Resolve optional parent_code values after every account has been upserted. */
    private function applyImportedParents(UploadedFile $file): array
    {
        $handle = fopen($file->getPathname(), 'r');
        if (! $handle) return [];
        $headers = array_map(fn ($value) => strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', (string) $value), '_')), fgetcsv($handle) ?: []);
        $codeIndex = array_search('code', $headers, true);
        if ($codeIndex === false) $codeIndex = array_search('account_code', $headers, true);
        $parentIndex = array_search('parent_code', $headers, true);
        if ($codeIndex === false || $parentIndex === false) { fclose($handle); return []; }

        $errors = [];
        $rowNumber = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNumber++;
            $code = trim((string) ($row[$codeIndex] ?? ''));
            $parentCode = trim((string) ($row[$parentIndex] ?? ''));
            if ($code === '' || $parentCode === '') continue;
            if ($code === $parentCode) { $errors[] = ['row' => $rowNumber, 'field' => 'parent_code', 'message' => 'An account cannot be its own parent']; continue; }
            $account = ChartOfAccount::where('code', $code)->first();
            $parent = ChartOfAccount::where('code', $parentCode)->first();
            if (! $account || ! $parent) { $errors[] = ['row' => $rowNumber, 'field' => 'parent_code', 'message' => "Parent account {$parentCode} was not found"]; continue; }
            $account->update(['parent_id' => $parent->id]);
        }
        fclose($handle);

        return $errors;
    }

    public function importTemplate(): StreamedResponse
    {
        return $this->importService->downloadTemplate([
            ['name' => 'code', 'example' => '1010'], ['name' => 'name', 'example' => 'Cash at Bank'],
            ['name' => 'type', 'example' => 'asset'], ['name' => 'subtype', 'example' => 'current_asset'], ['name' => 'parent_code', 'example' => '1000'],
            ['name' => 'description', 'example' => 'Operating bank account'], ['name' => 'is_active', 'example' => 'true'],
            ['name' => 'opening_balance', 'example' => '0.00'],
        ], 'chart-of-accounts-import-template.csv');
    }

    public function export(Request $request): StreamedResponse
    {
        $query = ChartOfAccount::with('parent')->orderBy('code');
        if ($request->filled('type') && $request->type !== 'all') $query->where('type', $request->type);
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q->where('code', 'ILIKE', "%{$search}%")->orWhere('name', 'ILIKE', "%{$search}%"));
        }

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['code', 'name', 'type', 'subtype', 'parent_code', 'description', 'is_active', 'is_system', 'opening_balance']);
            $query->chunk(500, function ($accounts) use ($handle) {
                foreach ($accounts as $account) fputcsv($handle, [
                    $account->code, $account->name, $account->type, $account->subtype,
                    $account->parent?->code, $account->description, $account->is_active ? 'true' : 'false',
                    $account->is_system ? 'true' : 'false', $account->opening_balance,
                ]);
            });
            fclose($handle);
        }, 'chart-of-accounts-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

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
                $query->where(function ($q) use ($search) {
                    $q->where('code', 'ILIKE', "%{$search}%")
                        ->orWhere('name', 'ILIKE', "%{$search}%");
                });
            }

            $accounts = $query->orderBy('code')->get();

            // Add balance to each account
            $accounts->each(function ($account) {
                $account->balance = $account->getBalance();
            });

            return response()->json([
                'data' => $accounts,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch accounts',
                'error' => $e->getMessage(),
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
                return ! isset($accountMap[$account->parent_id]);
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
                'data' => $buildTree($roots),
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to fetch account tree', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Failed to fetch account tree',
                'error' => $e->getMessage(),
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
                'data' => $account,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Account not found',
                'error' => $e->getMessage(),
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
                'code' => [
                    'required',
                    'string',
                    'max:20',
                    Rule::unique('chart_of_accounts', 'code')->whereNull('deleted_at'),
                ],
                'name' => 'required|string|max:255',
                'type' => 'required|in:asset,liability,equity,revenue,expense',
                'subtype' => 'nullable|string|max:50',
                'account_group' => 'nullable|string|max:20',
                'parent_id' => 'nullable|exists:chart_of_accounts,id',
                'description' => 'nullable|string',
                'is_active' => 'boolean',
                'opening_balance' => 'nullable|numeric',
            ]);

            $validated = $this->normalizeEmptyStrings($validated);

            $account = ChartOfAccount::create($validated);

            return response()->json([
                'data' => $account,
                'message' => 'Account created successfully',
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to create account',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update account
     *
     * System accounts are editable too (client request 2026-09-04: a client
     * needed a different account type on a system account). We no longer
     * hard-block code/type edits. Instead we (a) run the same validation as
     * for non-system accounts, (b) warn — but do not block — when a type or
     * code change conflicts with existing posted journal lines, so the
     * accountant can decide. Deleting system accounts remains blocked in
     * destroy().
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $account = ChartOfAccount::findOrFail($id);

            $validated = $request->validate([
                'code' => [
                    'required',
                    'string',
                    'max:20',
                    Rule::unique('chart_of_accounts', 'code')
                        ->whereNull('deleted_at')
                        ->ignore($id),
                ],
                'name' => 'required|string|max:255',
                'type' => 'required|in:asset,liability,equity,revenue,expense',
                'subtype' => 'nullable|string|max:50',
                'account_group' => 'nullable|string|max:20',
                'parent_id' => 'nullable|exists:chart_of_accounts,id',
                'description' => 'nullable|string',
                'is_active' => 'boolean',
            ]);

            // Normalize empty strings from frontend placeholder select values
            // to null so the database CHECK constraints accept them.
            $validated = $this->normalizeEmptyStrings($validated);

            // Prevent setting self as parent
            if (! empty($validated['parent_id']) && (int) $validated['parent_id'] === (int) $id) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => ['parent_id' => ['An account cannot be its own parent.']],
                ], 422);
            }

            $warnings = [];

            $newType = $validated['type'] ?? $account->type;

            // A type change flips the natural balance side. Warn (do not
            // block) if the account already has active posted journal lines,
            // because historical reports and the trial-balance sign will shift.
            if ($newType !== $account->type) {
                $hasActivePosted = $account->journalEntryLines()
                    ->whereHas('journalEntry', fn ($q) => $q->where('is_active_version', true))
                    ->exists();

                if ($hasActivePosted) {
                    $warnings[] = sprintf(
                        'Type changed from %s to %s but the account has posted journal entries. '
                        . 'Its reported balance sign and trial-balance classification will change for historical periods.',
                        $account->type,
                        $newType
                    );
                }

                // An existing subtype may no longer belong to the new type.
                // Drop a now-invalid subtype rather than persist a mismatch.
                if (array_key_exists('subtype', $validated)) {
                    $allowed = $this->subtypesForType($newType);
                    if ($validated['subtype'] !== null && ! in_array($validated['subtype'], $allowed, true)) {
                        $validated['subtype'] = null;
                        $warnings[] = 'Subtype was cleared because it is not valid for the new account type.';
                    }
                }
            }

            // Code changes are safe to persist — reports and journal lines
            // join by account id; level is a computed accessor, not a column.

            $account->update($validated);

            $message = $account->is_system
                ? 'System account updated successfully'
                : 'Account updated successfully';

            return response()->json([
                'data' => $account,
                'message' => $message,
                'warnings' => $warnings,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update account',
                'error' => $e->getMessage(),
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
                    'message' => 'Cannot delete system accounts',
                ], 403);
            }

            // Ledger guard: block only when the account is used by an
            // ACTIVE-version journal entry (posted or draft). Lines whose
            // entries were superseded by a correction (is_active_version
            // = false) are preserved audit history — the account row is
            // soft-deleted so those entries keep resolving it in the
            // database, while the account disappears from all live
            // lists, pickers and reports. Reversing an entry does NOT
            // delete its lines (double-entry history is immutable), so
            // the old blanket exists() check made fully-reversed test
            // accounts permanently undeletable (production issue
            // 2026-09-04, account 500001).
            $activeLedgerUsage = $account->journalEntryLines()
                ->whereHas('journalEntry', fn ($q) => $q->where('is_active_version', true))
                ->exists();

            if ($activeLedgerUsage) {
                return response()->json([
                    'message' => 'Cannot delete account with transactions. Correct or replace the active journal entries first, or deactivate the account instead.',
                ], 403);
            }

            // Check if account has children
            if ($account->children()->exists()) {
                return response()->json([
                    'message' => 'Cannot delete account with sub-accounts',
                ], 403);
            }

            // Config/line-reference scan: soft-deleting the account would
            // leave live pointers (journal templates, GL mappings, taxes,
            // products, fixed assets, document lines, company defaults)
            // dangling at an archived account. List every blocker so the
            // user can clear it from the owning screen and retry.
            $blockers = [];
            foreach (self::DELETION_REFERENCE_CHECKS as [$table, $column, $label]) {
                if (! \Illuminate\Support\Facades\Schema::hasTable($table)
                    || ! \Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
                    continue;
                }
                $count = \Illuminate\Support\Facades\DB::table($table)
                    ->where($column, $account->id)
                    ->count();
                if ($count > 0) {
                    $blockers[] = $label.' ('.$count.')';
                }
            }

            if (! empty($blockers)) {
                return response()->json([
                    'message' => 'Account is still referenced by: '.implode(', ', $blockers).'. Clear those references first.',
                    'references' => $blockers,
                ], 403);
            }

            $account->delete();

            return response()->json([
                'message' => 'Account deleted successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete account',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * [table, column, human label] for every live reference that must be
     * cleared before a chart-of-accounts row can be archived. Derived from
     * the FK graph on chart_of_accounts (2026-09-04). Excludes:
     * - chart_of_accounts.parent_id (covered by the children guard)
     * - journal_entry_lines (covered by the version-aware ledger guard)
     * - daily_ledger_summaries (derived, raw-joined history)
     */
    private const DELETION_REFERENCE_CHECKS = [
        ['account_mappings', 'account_id', 'account mappings'],
        ['bank_accounts', 'gl_account_id', 'bank accounts'],
        ['bill_lines', 'account_id', 'vendor bill lines'],
        ['company_settings', 'default_tax_account_id', 'company default accounts'],
        ['company_settings', 'default_receivable_account_id', 'company default accounts'],
        ['company_settings', 'default_payable_account_id', 'company default accounts'],
        ['company_settings', 'default_bank_account_id', 'company default accounts'],
        ['company_settings', 'default_revenue_account_id', 'company default accounts'],
        ['company_settings', 'default_expense_account_id', 'company default accounts'],
        ['company_settings', 'default_purchase_tax_account_id', 'company default accounts'],
        ['company_settings', 'default_inventory_account_id', 'company default accounts'],
        ['company_settings', 'default_cogs_account_id', 'company default accounts'],
        ['company_settings', 'default_retained_earnings_account_id', 'company default accounts'],
        ['company_settings', 'default_bank_charges_account_id', 'company default accounts'],
        ['customer_credit_note_lines', 'account_id', 'customer credit note lines'],
        ['entity_account_mappings', 'chart_of_account_id', 'entity account mappings'],
        ['entity_account_roles', 'chart_of_account_id', 'entity account roles'],
        ['fixed_assets', 'asset_account_id', 'fixed assets'],
        ['fixed_assets', 'accumulated_depreciation_account_id', 'fixed assets'],
        ['fixed_assets', 'depreciation_account_id', 'fixed assets'],
        ['invoice_lines', 'gl_account_id', 'invoice lines'],
        ['journals', 'default_debit_account_id', 'journal templates'],
        ['journals', 'default_credit_account_id', 'journal templates'],
        ['ocr_account_mappings', 'account_id', 'OCR account mappings'],
        ['product_categories', 'income_account_id', 'product categories'],
        ['products', 'expense_account_id', 'products'],
        ['products', 'income_account_id', 'products'],
        ['reconciliation_exceptions', 'bank_account_id', 'reconciliation exceptions'],
        ['sales_order_lines', 'income_account_id', 'sales order lines'],
        ['tax_rates', 'tax_account_id', 'tax rates'],
        ['vendor_credit_note_lines', 'account_id', 'vendor credit note lines'],
        ['vendor_debit_note_lines', 'account_id', 'vendor debit note lines'],
        ['web_order_items', 'income_account_id', 'web order items'],
    ];

    /**
     * Convert empty-string fields to null so the DB CHECK constraints
     * (which expect NULL for unset enum-typed columns) accept the payload.
     * The frontend uses Radix Select with value 'none' mapped to '' for
     * "no selection" placeholders; that empty string would violate the
     * chart_of_accounts_subtype_check / type_check constraints.
     */
    private function normalizeEmptyStrings(array $values): array
    {
        foreach ($values as $key => $value) {
            if ($value === '' || $value === 'none') {
                $values[$key] = null;
            }
        }

        return $values;
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

    /**
     * Subtypes valid per account type — mirrors the frontend subtype picker
     * and stays within the chart_of_accounts_subtype_check DB constraint.
     *
     * @return string[]
     */
    private function subtypesForType(string $type): array
    {
        return [
            'asset' => ['current_asset', 'fixed_asset', 'other_asset', 'asset_receivable', 'asset_current', 'asset_cash', 'asset_fixed', 'asset_non_current'],
            'liability' => ['current_liability', 'long_term_liability', 'liability_payable', 'liability_current', 'liability_non_current'],
            'equity' => ['equity'],
            'revenue' => ['operating_revenue', 'other_revenue', 'income', 'income_other'],
            'expense' => ['cost_of_goods_sold', 'operating_expense', 'other_expense', 'expense_direct_cost', 'expense', 'expense_depreciation'],
        ][$type] ?? [];
    }
}
