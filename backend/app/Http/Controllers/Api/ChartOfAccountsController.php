<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Platform\Import\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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

                $validated = $this->normalizeEmptyStrings($validated);

                $account->update($validated);

                return response()->json([
                    'data' => $account,
                    'message' => 'System account updated successfully',
                ]);
            }

            $validated = $request->validate([
                'code' => 'required|string|max:20|unique:chart_of_accounts,code,'.$id,
                'name' => 'required|string|max:255',
                'type' => 'required|in:asset,liability,equity,revenue,expense',
                'subtype' => 'nullable|string|max:50',
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

            $account->update($validated);

            return response()->json([
                'data' => $account,
                'message' => 'Account updated successfully',
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

            // Check if account has transactions
            if ($account->journalEntryLines()->exists()) {
                return response()->json([
                    'message' => 'Cannot delete account with transactions',
                ], 403);
            }

            // Check if account has children
            if ($account->children()->exists()) {
                return response()->json([
                    'message' => 'Cannot delete account with sub-accounts',
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
}
