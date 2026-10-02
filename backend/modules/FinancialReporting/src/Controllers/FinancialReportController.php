<?php

namespace Modules\FinancialReporting\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\Cache\ReportCacheService;
use Modules\FinancialReporting\Services\Reports\BalanceSheetService;
use Modules\FinancialReporting\Services\Reports\CashFlowService;
use Modules\FinancialReporting\Services\Reports\GeneralLedgerService;
use Modules\FinancialReporting\Services\Reports\ProfitLossService;
use Modules\FinancialReporting\Services\Reports\TrialBalanceService;

class FinancialReportController extends Controller
{
    public function __construct(
        protected ProfitLossService $profitLossService,
        protected BalanceSheetService $balanceSheetService,
        protected CashFlowService $cashFlowService,
        protected TrialBalanceService $trialBalanceService,
        protected GeneralLedgerService $generalLedgerService,
        protected ReportCacheService $cacheService
    ) {}

    /**
     * Generate Profit & Loss report
     */
    public function profitLoss(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'compare_start_date' => 'nullable|date',
            'compare_end_date' => 'nullable|date|after_or_equal:compare_start_date',
            'department_id' => 'nullable|integer|exists:departments,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'location_id' => 'nullable|integer|exists:locations,id',
            'interval' => 'nullable|in:day,week,month,quarter,year',
            // Issue #875: ReportFilter already supports the entity/book
            // filters and ProfitLossService applies them; the validation
            // whitelist was silently dropping them.
            'legal_entity_id' => 'nullable|integer',
            'entity_book_id' => 'nullable|integer',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);

            // Validate date range
            $maxDays = config('financialreporting.reports.max_date_range', 365);
            if (! $filter->validateDateRange($maxDays)) {
                return response()->json([
                    'error' => "Date range cannot exceed {$maxDays} days",
                ], 422);
            }

            $reportData = $this->profitLossService->generate($filter);

            // Pass through the service's native structure which already matches frontend expectations
            $response = [
                'revenue' => $reportData->data['revenue'] ?? ['total' => 0, 'accounts' => []],
                'cost_of_goods_sold' => $reportData->data['cost_of_goods_sold'] ?? ['total' => 0, 'accounts' => []],
                'operating_expenses' => $reportData->data['operating_expenses'] ?? ['total' => 0, 'accounts' => []],
                'other_income' => $reportData->data['other_income'] ?? ['total' => 0, 'accounts' => []],
                'other_expenses' => $reportData->data['other_expenses'] ?? ['total' => 0, 'accounts' => []],
                'gross_profit' => $reportData->data['gross_profit'] ?? ['amount' => 0],
                'operating_profit' => $reportData->data['operating_profit'] ?? ['amount' => 0],
                'net_profit' => $reportData->data['net_profit'] ?? ['amount' => 0],
            ];

            // Add comparison if available
            if ($reportData->comparison) {
                $response['comparison'] = $reportData->comparison;
            }

            // Add no-cache headers to prevent browser caching
            return response()->json([
                'success' => true,
                'data' => $response,
            ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Profit & Loss report generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to generate report. Please try again.',
            ], 500);
        }
    }

    /**
     * Generate Balance Sheet report
     */
    public function balanceSheet(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'as_of_date' => 'required|date',
            'compare_date' => 'nullable|date',
            'department_id' => 'nullable|integer|exists:departments,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'location_id' => 'nullable|integer|exists:locations,id',
        ]);

        // Map compare_date to compare_start_date for ReportFilter
        if (isset($validated['compare_date'])) {
            $validated['compare_start_date'] = $validated['compare_date'];
        }

        try {
            $filter = ReportFilter::fromRequest($validated);

            $reportData = $this->balanceSheetService->generate($filter);

            $currentAssets = $reportData->data['assets']['current_assets']['accounts'] ?? [];
            $fixedAssets = $reportData->data['assets']['fixed_assets']['accounts'] ?? [];
            $otherAssets = $reportData->data['assets']['other_assets']['accounts'] ?? [];
            $currentLiabilities = $reportData->data['liabilities']['current_liabilities']['accounts'] ?? [];
            $longTermLiabilities = $reportData->data['liabilities']['long_term_liabilities']['accounts'] ?? [];

            $totalAssets = $reportData->summary['total_assets'] ?? 0;
            $totalLiabilities = $reportData->summary['total_liabilities'] ?? 0;
            $totalEquity = $reportData->summary['total_equity'] ?? 0;

            $equityAccounts = $reportData->data['equity']['accounts'] ?? [];
            $currentYearEarnings = (float) ($reportData->data['equity']['current_year_earnings'] ?? 0);
            $equityGroups = $this->groupBalanceSheetAccounts($equityAccounts);

            if (abs($currentYearEarnings) >= 0.005) {
                $equityGroups[] = [
                    'code' => '',
                    'name' => 'Calculated earnings',
                    'accounts' => [[
                        'account_id' => 0,
                        'code' => '',
                        'name' => 'Current Year Earnings (Net Profit/Loss)',
                        'balance' => $currentYearEarnings,
                    ]],
                    'total' => $currentYearEarnings,
                ];
            }

            // Per-tenant presentation filter: account/group codes listed in
            // the tenant setting `reporting.balance_sheet.hidden_account_codes`
            // are omitted from the UI groups. Section TOTALS are intentionally
            // NOT adjusted — this is a presentation-only filter agreed with
            // the operator (the GL figures and A = L + E remain unchanged).
            $hiddenCodes = $this->hiddenStatementLineCodes();

            $response = [
                'assets' => [
                    'current' => $this->filterHiddenStatementLines(
                        $this->groupBalanceSheetAccounts($currentAssets),
                        $hiddenCodes
                    ),
                    'non_current' => $this->filterHiddenStatementLines(
                        $this->groupBalanceSheetAccounts([...$fixedAssets, ...$otherAssets]),
                        $hiddenCodes
                    ),
                    'total' => $totalAssets,
                ],
                'liabilities' => [
                    'current' => $this->filterHiddenStatementLines(
                        $this->groupBalanceSheetAccounts($currentLiabilities),
                        $hiddenCodes
                    ),
                    'non_current' => $this->filterHiddenStatementLines(
                        $this->groupBalanceSheetAccounts($longTermLiabilities),
                        $hiddenCodes
                    ),
                    'total' => $totalLiabilities,
                ],
                'equity' => [
                    'groups' => $this->filterHiddenStatementLines($equityGroups, $hiddenCodes),
                    'total' => $totalEquity,
                ],
                'total_assets' => $totalAssets,
                'total_liabilities' => $totalLiabilities,
                'total_equity' => $totalEquity,
                'total_liabilities_and_equity' => round((float) ($totalLiabilities + $totalEquity), 2),
                'working_capital' => $reportData->summary['working_capital'] ?? 0,
                'balance_check' => round((float) ($totalAssets - $totalLiabilities - $totalEquity), 2),
            ];

            // Add comparison if available
            if ($reportData->comparison) {
                $response['comparison'] = $reportData->comparison;
            }

            // Add no-cache headers to prevent browser caching
            return response()->json([
                'success' => true,
                'data' => $response,
            ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Balance Sheet report generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to generate report. Please try again.',
            ], 500);
        }
    }

    private function normalizeBalanceSheetAccounts(array $accounts): array
    {
        return array_map(function (array $account) {
            return [
                'account_id' => $account['account_id'] ?? $account['id'] ?? 0,
                'code' => $account['code'] ?? '',
                'name' => $account['name'] ?? '',
                'currency_code' => $account['currency_code'] ?? null,
                'balance' => (float) ($account['balance'] ?? 0),
            ];
        }, $accounts);
    }

    /**
     * Build statement captions dynamically from bank links and the COA
     * hierarchy. No posting account IDs or code ranges are embedded here.
     */
    private function groupBalanceSheetAccounts(array $accounts): array
    {
        $groups = [];

        foreach ($accounts as $account) {
            if (! empty($account['parent_id'])) {
                $groupCode = (string) ($account['parent_code'] ?: 'PARENT-'.$account['parent_id']);
                $groupName = (string) ($account['parent_name'] ?: $groupCode);
            } elseif (! empty($account['account_group'])) {
                $groupCode = (string) $account['account_group'];
                $groupName = (string) ($account['group_name'] ?: $account['account_group']);
            } elseif (! empty($account['currency_code'])) {
                $groupCode = 'BANK_BALANCES';
                $groupName = 'Bank balances';
            } else {
                $groupCode = (string) $account['code'];
                $groupName = (string) $account['name'];
            }

            if (! isset($groups[$groupCode])) {
                $groups[$groupCode] = [
                    'code' => $groupCode,
                    'name' => $groupName,
                    'accounts' => [],
                    'total' => 0.0,
                ];
            }

            $normalized = $this->normalizeBalanceSheetAccounts([$account])[0];
            $groups[$groupCode]['accounts'][] = $normalized;
            $groups[$groupCode]['total'] += $normalized['balance'];
        }

        return array_values($groups);
    }

    /**
     * Per-tenant statement-line presentation filter.
     *
     * Reads the tenant setting `reporting.balance_sheet.hidden_account_codes`
     * (an array of account codes and/or group codes). Matching groups are
     * omitted entirely; matching accounts are removed from their group, and
     * a group left with no accounts is dropped.
     *
     * Section totals are deliberately NOT recomputed — this is a display-only
     * filter, so the accounting equation and the underlying GL figures stay
     * exactly as reported.
     *
     * @return array<int, string>
     */
    private function hiddenStatementLineCodes(): array
    {
        try {
            $codes = \App\Models\Setting::getValue('reporting.balance_sheet.hidden_account_codes', []);
        } catch (\Throwable $e) {
            return [];
        }

        if (! is_array($codes)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($code) => (string) $code, $codes),
            static fn ($code) => $code !== ''
        ));
    }

    /**
     * @param  array<int, array{code: string, name: string, accounts: array<int, array<string, mixed>>, total: float}>  $groups
     * @param  array<int, string>  $hiddenCodes
     * @return array<int, array<string, mixed>>
     */
    private function filterHiddenStatementLines(array $groups, array $hiddenCodes): array
    {
        if ($hiddenCodes === []) {
            return $groups;
        }

        $hiddenLookup = array_flip($hiddenCodes);
        $filtered = [];

        foreach ($groups as $group) {
            // Whole group hidden by code, or a single-account group whose
            // account code is hidden (kept for clarity even though the
            // account loop below would also catch it).
            if (isset($hiddenLookup[(string) ($group['code'] ?? '')])) {
                continue;
            }

            $accounts = array_values(array_filter(
                $group['accounts'] ?? [],
                static fn ($account) => ! isset($hiddenLookup[(string) ($account['code'] ?? '')])
            ));

            if ($accounts === []) {
                continue;
            }

            $group['accounts'] = $accounts;
            $filtered[] = $group;
        }

        return $filtered;
    }

    /**
     * Generate Cash Flow report
     */
    public function cashFlow(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'method' => 'nullable|in:direct,indirect',
            'department_id' => 'nullable|integer|exists:departments,id',
            'project_id' => 'nullable|integer|exists:projects,id',
            'location_id' => 'nullable|integer|exists:locations,id',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);

            // Set method in interval field (reusing existing field)
            $filter->interval = $validated['method'] ?? 'indirect';

            // Validate date range
            $maxDays = config('financialreporting.reports.max_date_range', 365);
            if (! $filter->validateDateRange($maxDays)) {
                return response()->json([
                    'error' => "Date range cannot exceed {$maxDays} days",
                ], 422);
            }

            // Get report with caching
            $reportData = $this->cacheService->remember(
                reportType: 'cash-flow',
                filter: $filter,
                callback: fn () => $this->cashFlowService->generate($filter),
                userId: $request->user()?->id
            );

            // Map to frontend expected structure
            $response = [
                'operating_activities' => [
                    'items' => $reportData->data['operating_activities']['items'] ?? [],
                    'total' => $reportData->data['operating_activities']['net_cash'] ?? 0,
                ],
                'investing_activities' => [
                    'items' => $reportData->data['investing_activities']['items'] ?? [],
                    'total' => $reportData->data['investing_activities']['net_cash'] ?? 0,
                ],
                'financing_activities' => [
                    'items' => $reportData->data['financing_activities']['items'] ?? [],
                    'total' => $reportData->data['financing_activities']['net_cash'] ?? 0,
                ],
                'net_cash_flow' => $reportData->data['net_cash_flow'] ?? 0,
                'opening_cash' => $reportData->data['beginning_cash'] ?? 0,
                'closing_cash' => $reportData->data['ending_cash'] ?? 0,
            ];

            return response()->json([
                'success' => true,
                'data' => $response,
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Cash Flow report generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to generate report. Please try again.',
            ], 500);
        }
    }

    /**
     * Generate Trial Balance report
     */
    public function trialBalance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'as_of_date' => 'required|date',
            'compare_date' => 'nullable|date',
            'account_type' => 'nullable|in:asset,liability,equity,revenue,expense',
            'grouped' => 'nullable|boolean',
        ]);

        // Map compare_date to compare_start_date for ReportFilter
        if (isset($validated['compare_date'])) {
            $validated['compare_start_date'] = $validated['compare_date'];
        }

        try {
            $filter = ReportFilter::fromRequest($validated);

            // Store account_type in the filter's interval field (reusing existing field)
            $filter->accountType = $validated['account_type'] ?? null;

            // Check if grouped view is requested
            $grouped = $validated['grouped'] ?? false;

            if ($grouped) {
                // Get grouped trial balance
                $reportData = $this->trialBalanceService->getGroupedByAccountType($filter->endDate);

                // Flatten the response structure
                return response()->json([
                    'success' => true,
                    'data' => $reportData,
                ]);
            }

            $reportData = $this->trialBalanceService->generate($filter);

            // Flatten the response to match frontend expectations
            $response = [
                'accounts' => $reportData->data,
                'total_debits' => $reportData->summary['total_debits'] ?? 0,
                'total_credits' => $reportData->summary['total_credits'] ?? 0,
                'is_balanced' => $reportData->summary['is_balanced'] ?? false,
                'difference' => $reportData->summary['difference'] ?? 0,
                'as_of_date' => $reportData->metadata['as_of_date'] ?? null,
                'account_count' => $reportData->metadata['account_count'] ?? 0,
            ];

            // Generate comparison if requested
            if ($filter->compareStartDate) {
                $compareFilter = new ReportFilter(
                    asOfDate: $filter->compareStartDate
                );
                $compareData = $this->trialBalanceService->generate($compareFilter);

                $response['comparison'] = [
                    'as_of_date' => $filter->compareStartDate->format('Y-m-d'),
                    'total_debits' => $compareData->summary['total_debits'] ?? 0,
                    'total_credits' => $compareData->summary['total_credits'] ?? 0,
                    'accounts' => $compareData->data ?? [],
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $response,
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Trial Balance report generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to generate report. Please try again.',
            ], 500);
        }
    }

    /**
     * Generate General Ledger report
     */
    public function generalLedger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => 'nullable|integer',  // Made optional
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'page' => 'nullable|integer|min:1',
            // 0 explicitly requests every entry in the filtered period.
            'per_page' => 'nullable|integer|min:0|max:100',
            'summary' => 'nullable|boolean',
            'account_type' => 'nullable|in:asset,liability,equity,revenue,expense',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);

            // If no account_id provided, show multi-account summary
            if (! isset($validated['account_id'])) {
                $reportData = $this->generalLedgerService->getMultiAccountSummary($filter);
                $hierarchy = $this->generalLedgerService->getHierarchy($filter);

                // Transform accounts into transaction-like format for frontend compatibility
                $transactions = array_map(function ($account) {
                    return [
                        'date' => '',  // No specific date for summary
                        'reference' => $account['code'],
                        'description' => $account['name'].' ('.$account['type'].')',
                        'debit' => $account['total_debit'],
                        'credit' => $account['total_credit'],
                        'balance' => $account['net_change'],
                    ];
                }, $reportData['accounts']);

                return response()->json([
                    'success' => true,
                    'data' => [
                        'account' => null,  // No specific account
                        'opening_balance' => 0,
                        'transactions' => $transactions,
                        'closing_balance' => array_sum(array_column($reportData['accounts'], 'net_change')),
                        'pagination' => null,
                        'hierarchy' => $hierarchy,
                    ],
                ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                    ->header('Pragma', 'no-cache')
                    ->header('Expires', '0');
            }

            // Check if summary view is requested (multi-account summary)
            if ($validated['summary'] ?? false) {
                $reportData = $this->generalLedgerService->getMultiAccountSummary($filter);

                // Transform accounts into transaction-like format for frontend compatibility
                $transactions = array_map(function ($account) {
                    return [
                        'date' => '',  // No specific date for summary
                        'reference' => $account['code'],
                        'description' => $account['name'].' ('.$account['type'].')',
                        'debit' => $account['total_debit'],
                        'credit' => $account['total_credit'],
                        'balance' => $account['net_change'],
                    ];
                }, $reportData['accounts']);

                return response()->json([
                    'success' => true,
                    'data' => [
                        'account' => null,  // No specific account
                        'opening_balance' => 0,
                        'transactions' => $transactions,
                        'closing_balance' => array_sum(array_column($reportData['accounts'], 'net_change')),
                        'pagination' => null,
                    ],
                ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                    ->header('Pragma', 'no-cache')
                    ->header('Expires', '0');
            }

            // Validate date range if provided
            if ($filter->startDate && $filter->endDate) {
                $maxDays = config('financialreporting.reports.max_date_range', 365);
                if (! $filter->validateDateRange($maxDays)) {
                    return response()->json([
                        'error' => "Date range cannot exceed {$maxDays} days",
                    ], 422);
                }
            }

            // Get detailed general ledger for specific account
            // Note: We don't cache paginated results as they change frequently
            $reportData = $this->generalLedgerService->generate($filter);

            // Map to frontend expected structure
            $response = [
                'account' => $reportData->metadata['account'] ?? null,
                'opening_balance' => $reportData->summary['opening_balance'] ?? 0,
                'transactions' => array_map(function ($tx) {
                    return [
                        'date' => $tx['entry_date'],
                        'reference' => $tx['reference'],
                        'description' => $tx['description'],
                        'debit' => $tx['debit'],
                        'credit' => $tx['credit'],
                        'balance' => $tx['balance'],
                        'journal_entry_id' => $tx['journal_entry_id'] ?? null,
                        'invoice_id' => $tx['invoice_id'] ?? null,
                    ];
                }, $reportData->data),
                'closing_balance' => $reportData->summary['closing_balance'] ?? 0,
                'pagination' => $reportData->metadata['pagination'] ?? null,
            ];

            // Add no-cache headers to prevent browser caching
            return response()->json([
                'success' => true,
                'data' => $response,
            ])->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('General Ledger report generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to generate report. Please try again.',
            ], 500);
        }
    }
}
