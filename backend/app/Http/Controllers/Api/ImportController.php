<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Services\BillPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * @deprecated Phase 3 — Use MigrationWorkbench routes instead.
 * This controller will be removed after one release cycle.
 * See docs/architecture/IMPLEMENTATION-PLAN-ACCOUNTING-CONVERGENCE.md Task 3.17.
 */
class ImportController extends Controller
{
    public function __construct(
        private BillPaymentService $billPaymentService,
    ) {}

    /**
     * Handle file import
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:10240', // 10MB max
            'type' => 'required|in:chart-of-accounts,payments',
        ]);

        try {
            $type = $request->type;

            // Route to appropriate import handler
            switch ($type) {
                case 'chart-of-accounts':
                    return $this->importChartOfAccounts($request);
                case 'payments':
                    return $this->importVendorPayments($request);
                default:
                    return response()->json([
                        'message' => 'Invalid import type',
                        'error' => 'Unknown import type',
                    ], 400);
            }

        } catch (\Exception $e) {
            Log::error('Import error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Import failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Import Chart of Accounts from Excel
     */
    private function importChartOfAccounts(Request $request)
    {
        $file = $request->file('file');
        $filename = time().'_'.$file->getClientOriginalName();
        $path = $file->storeAs('imports', $filename);
        $fullPath = Storage::path($path);

        Log::info('Chart of Accounts import started', [
            'file' => $filename,
            'path' => $fullPath,
        ]);

        try {
            // Load spreadsheet
            $spreadsheet = IOFactory::load($fullPath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            // Find header row and map columns
            $headerRow = null;
            $columnMap = [];

            foreach ($rows as $index => $row) {
                if ($this->isHeaderRow($row)) {
                    $headerRow = $index;
                    $columnMap = $this->mapColumns($row);
                    break;
                }
            }

            if ($headerRow === null) {
                throw new \Exception('Could not find header row in Excel file');
            }

            // Process data rows
            $dataRows = array_slice($rows, $headerRow + 1);
            $results = [
                'total' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => [],
            ];

            DB::beginTransaction();

            foreach ($dataRows as $rowIndex => $row) {
                $actualRowNumber = $headerRow + $rowIndex + 2; // +2 for header and 0-index

                // Skip empty rows
                if ($this->isEmptyRow($row)) {
                    continue;
                }

                $results['total']++;

                try {
                    $accountData = $this->extractAccountData($row, $columnMap);

                    // Validate required fields
                    if (empty($accountData['code']) || empty($accountData['name'])) {
                        $results['errors'][] = "Row {$actualRowNumber}: Missing code or name";
                        $results['skipped']++;

                        continue;
                    }

                    // Check if account exists
                    $existing = ChartOfAccount::where('code', $accountData['code'])->first();

                    if ($existing) {
                        // Skip existing accounts to preserve accounting data integrity
                        $results['skipped']++;
                        Log::info("Skipped existing account: {$accountData['code']}");
                    } else {
                        // Create new account
                        ChartOfAccount::create($accountData);
                        $results['created']++;
                        Log::info("Created account: {$accountData['code']}");
                    }

                } catch (\Exception $e) {
                    $results['errors'][] = "Row {$actualRowNumber}: ".$e->getMessage();
                    $results['skipped']++;
                    Log::warning("Failed to import row {$actualRowNumber}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            DB::commit();

            // Clean up uploaded file
            Storage::delete($path);

            Log::info('Chart of Accounts import completed', $results);

            return response()->json([
                'message' => 'Import completed',
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Storage::delete($path);

            Log::error('Chart of Accounts import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Check if row is a header row
     */
    private function isHeaderRow(array $row): bool
    {
        $headerKeywords = ['code', 'account', 'name', 'type'];
        $rowString = strtolower(implode(' ', array_filter($row)));

        foreach ($headerKeywords as $keyword) {
            if (strpos($rowString, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map column names to indices
     */
    private function mapColumns(array $headerRow): array
    {
        $map = [];

        foreach ($headerRow as $index => $header) {
            $header = strtolower(trim($header));

            // Map various column name variations
            if (in_array($header, ['code', 'account code', 'account_code', 'acct code'])) {
                $map['code'] = $index;
            } elseif (in_array($header, ['name', 'account name', 'account_name', 'description'])) {
                $map['name'] = $index;
            } elseif (in_array($header, ['type', 'account type', 'account_type', 'acct type'])) {
                $map['subtype'] = $index; // Jingxi uses account_type for subtype
            } elseif (in_array($header, ['internal_group', 'internal group', 'main_type', 'category'])) {
                $map['type'] = $index; // Jingxi uses internal_group for main type
            } elseif (in_array($header, ['parent', 'parent code', 'parent_code', 'parent account'])) {
                $map['parent_code'] = $index;
            } elseif (in_array($header, ['currency', 'currency code', 'currency_code'])) {
                $map['currency'] = $index;
            } elseif (in_array($header, ['active', 'is active', 'is_active', 'status'])) {
                $map['is_active'] = $index;
            } elseif (in_array($header, ['account group', 'account_group', 'group', 'sfrs_group'])) {
                $map['account_group'] = $index;
            }
        }

        return $map;
    }

    /**
     * Check if row is empty
     */
    private function isEmptyRow(array $row): bool
    {
        return empty(array_filter($row, function ($cell) {
            return ! empty(trim($cell));
        }));
    }

    /**
     * Extract account data from row
     */
    private function extractAccountData(array $row, array $columnMap): array
    {
        // Get type from internal_group column (or type column as fallback)
        $typeValue = 'asset';
        if (isset($columnMap['type']) && ! empty($row[$columnMap['type']])) {
            $typeValue = trim($row[$columnMap['type']]);
        }
        $normalizedType = $this->normalizeAccountType($typeValue);

        // Get subtype from account_type column and normalize it
        $subtypeValue = '';
        if (isset($columnMap['subtype']) && ! empty($row[$columnMap['subtype']])) {
            $subtypeValue = trim($row[$columnMap['subtype']]);
        }
        $normalizedSubtype = $this->normalizeAccountSubtype($subtypeValue, $normalizedType);

        $data = [
            'code' => isset($columnMap['code']) ? trim($row[$columnMap['code']]) : null,
            'name' => isset($columnMap['name']) ? trim($row[$columnMap['name']]) : null,
            'type' => $normalizedType,
            'subtype' => $normalizedSubtype,
            'account_group' => isset($columnMap['account_group']) && ! empty($row[$columnMap['account_group']])
                ? trim($row[$columnMap['account_group']])
                : null,
            'currency_code' => isset($columnMap['currency']) ? strtoupper(trim($row[$columnMap['currency']])) : 'USD',
            'is_active' => true,
        ];

        // Handle parent account
        if (isset($columnMap['parent_code']) && ! empty($row[$columnMap['parent_code']])) {
            $parentCode = trim($row[$columnMap['parent_code']]);
            $parent = ChartOfAccount::where('code', $parentCode)->first();
            if ($parent) {
                $data['parent_id'] = $parent->id;
            }
        }

        // Handle is_active
        if (isset($columnMap['is_active'])) {
            $activeValue = strtolower(trim($row[$columnMap['is_active']]));
            $data['is_active'] = in_array($activeValue, ['1', 'true', 'yes', 'active', 'y']);
        }

        return $data;
    }

    /**
     * Normalize account type to match database enum
     */
    private function normalizeAccountType(string $type): string
    {
        $type = strtolower(trim($type));

        // Map various type names to database enum values (singular)
        $typeMap = [
            'asset' => 'asset',
            'assets' => 'asset',
            'liability' => 'liability',
            'liabilities' => 'liability',
            'equity' => 'equity',
            'revenue' => 'revenue',
            'revenues' => 'revenue',
            'income' => 'revenue',  // Jingxi uses 'income' for revenue
            'expense' => 'expense',
            'expenses' => 'expense',
            'cost' => 'expense',
        ];

        return $typeMap[$type] ?? 'asset';
    }

    /**
     * Normalize account subtype to match database enum
     * Handles multiple input formats and maps them to valid Jingxi-specific database values
     */
    private function normalizeAccountSubtype(string $originalSubtype, string $normalizedType): string
    {
        if (empty($originalSubtype)) {
            // Return default subtype based on main type if no subtype provided
            $defaultSubtypes = [
                'asset' => 'asset_current',
                'liability' => 'liability_current',
                'equity' => 'equity',
                'revenue' => 'income',
                'expense' => 'expense',
            ];

            return $defaultSubtypes[$normalizedType] ?? 'asset_current';
        }

        $originalSubtype = strtolower(trim($originalSubtype));

        // Comprehensive mapping for all possible input variations
        // Maps to Jingxi-specific values (preferred) or keeps generic values for backward compatibility
        $subtypeMap = [
            // Asset subtypes - Jingxi format (store as-is)
            'asset_receivable' => 'asset_receivable',
            'asset_current' => 'asset_current',
            'asset_cash' => 'asset_cash',
            'asset_fixed' => 'asset_fixed',
            'asset_non_current' => 'asset_non_current',
            'asset_noncurrent' => 'asset_non_current',

            // Asset subtypes - Generic format (keep for backward compatibility)
            'current_asset' => 'current_asset',
            'current asset' => 'current_asset',
            'fixed_asset' => 'fixed_asset',
            'fixed asset' => 'fixed_asset',
            'other_asset' => 'other_asset',
            'other asset' => 'other_asset',

            // Asset subtypes - Alternative names (map to Jingxi format)
            'receivable' => 'asset_receivable',
            'receivables' => 'asset_receivable',
            'cash' => 'asset_cash',
            'fixed' => 'asset_fixed',
            'non-current' => 'asset_non_current',
            'noncurrent' => 'asset_non_current',

            // Liability subtypes - Jingxi format (store as-is)
            'liability_payable' => 'liability_payable',
            'liability_current' => 'liability_current',
            'liability_non_current' => 'liability_non_current',
            'liability_noncurrent' => 'liability_non_current',

            // Liability subtypes - Generic format (keep for backward compatibility)
            'current_liability' => 'current_liability',
            'current liability' => 'current_liability',
            'long_term_liability' => 'long_term_liability',
            'long term liability' => 'long_term_liability',
            'longterm_liability' => 'long_term_liability',

            // Liability subtypes - Alternative names (map to Jingxi format)
            'payable' => 'liability_payable',
            'payables' => 'liability_payable',
            'long-term' => 'liability_non_current',
            'longterm' => 'liability_non_current',

            // Equity subtype
            'equity' => 'equity',

            // Revenue subtypes - Jingxi format (store as-is)
            'income' => 'income',
            'income_other' => 'income_other',

            // Revenue subtypes - Generic format (keep for backward compatibility)
            'operating_revenue' => 'operating_revenue',
            'operating revenue' => 'operating_revenue',
            'other_revenue' => 'other_revenue',
            'other revenue' => 'other_revenue',

            // Revenue subtypes - Alternative names (map to Jingxi format)
            'revenue' => 'income',
            'sales' => 'income',

            // Expense subtypes - Jingxi format (store as-is)
            'expense_direct_cost' => 'expense_direct_cost',
            'expense' => 'expense',
            'expense_depreciation' => 'expense_depreciation',

            // Expense subtypes - Generic format (keep for backward compatibility)
            'cost_of_goods_sold' => 'cost_of_goods_sold',
            'cost of goods sold' => 'cost_of_goods_sold',
            'operating_expense' => 'operating_expense',
            'operating expense' => 'operating_expense',
            'other_expense' => 'other_expense',
            'other expense' => 'other_expense',

            // Expense subtypes - Alternative names (map to Jingxi format)
            'cogs' => 'expense_direct_cost',
            'direct cost' => 'expense_direct_cost',
            'direct_cost' => 'expense_direct_cost',
            'depreciation' => 'expense_depreciation',
        ];

        // Check if we have a direct mapping
        if (isset($subtypeMap[$originalSubtype])) {
            return $subtypeMap[$originalSubtype];
        }

        // If no mapping found, try to infer from the type
        // This handles cases where Excel might have unexpected values
        $defaultSubtypes = [
            'asset' => 'asset_current',
            'liability' => 'liability_current',
            'equity' => 'equity',
            'revenue' => 'income',
            'expense' => 'expense',
        ];

        return $defaultSubtypes[$normalizedType] ?? 'asset_current';
    }

    /**
     * Import Vendor Payments from Excel
     *
     * Workstream 1 (2026-04-15): Routed through BillPaymentService to ensure
     * all vendor payments post to GL via the canonical posting control plane.
     */
    private function importVendorPayments(Request $request)
    {
        $file = $request->file('file');
        $filename = time().'_'.$file->getClientOriginalName();
        $path = $file->storeAs('imports', $filename);
        $fullPath = Storage::path($path);

        Log::info('Vendor Payments import started', [
            'file' => $filename,
            'path' => $fullPath,
        ]);

        try {
            // Load spreadsheet
            $spreadsheet = IOFactory::load($fullPath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            // Find header row and map columns
            $headerRow = null;
            $columnMap = [];

            foreach ($rows as $index => $row) {
                if ($this->isPaymentHeaderRow($row)) {
                    $headerRow = $index;
                    $columnMap = $this->mapPaymentColumns($row);
                    break;
                }
            }

            if ($headerRow === null) {
                throw new \Exception('Could not find header row in Excel file');
            }

            // Process data rows
            $dataRows = array_slice($rows, $headerRow + 1);
            $results = [
                'total' => 0,
                'created' => 0,
                'skipped' => 0,
                'errors' => [],
            ];

            foreach ($dataRows as $rowIndex => $row) {
                $actualRowNumber = $headerRow + $rowIndex + 2;

                // Skip empty rows
                if ($this->isEmptyRow($row)) {
                    continue;
                }

                $results['total']++;

                try {
                    $paymentData = $this->extractPaymentData($row, $columnMap);

                    // Validate required fields
                    if (empty($paymentData['vendor_id'])) {
                        $results['errors'][] = "Row {$actualRowNumber}: Vendor not found";
                        $results['skipped']++;

                        continue;
                    }

                    if (empty($paymentData['payment_date']) || empty($paymentData['amount'])) {
                        $results['errors'][] = "Row {$actualRowNumber}: Missing payment date or amount";
                        $results['skipped']++;

                        continue;
                    }

                    // Create payment via BillPaymentService (posts to GL and updates bill balances)
                    $payment = $this->billPaymentService->recordPayment($paymentData, $paymentData['created_by'] ?? null);
                    $results['created']++;

                    Log::info("Created payment via BillPaymentService: {$payment->payment_number}");

                } catch (\Exception $e) {
                    $results['errors'][] = "Row {$actualRowNumber}: ".$e->getMessage();
                    $results['skipped']++;
                    Log::warning("Failed to import payment row {$actualRowNumber}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Clean up uploaded file
            Storage::delete($path);

            Log::info('Vendor Payments import completed', $results);

            return response()->json([
                'message' => 'Import completed',
                'results' => $results,
            ]);

        } catch (\Exception $e) {
            Storage::delete($path);

            Log::error('Vendor Payments import failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Check if row is a payment header row
     */
    private function isPaymentHeaderRow(array $row): bool
    {
        $headerKeywords = ['payment', 'vendor', 'date', 'amount'];
        $rowString = strtolower(implode(' ', array_filter($row)));

        $matchCount = 0;
        foreach ($headerKeywords as $keyword) {
            if (strpos($rowString, $keyword) !== false) {
                $matchCount++;
            }
        }

        return $matchCount >= 2;
    }

    /**
     * Map payment column names to indices
     */
    private function mapPaymentColumns(array $headerRow): array
    {
        $map = [];

        foreach ($headerRow as $index => $header) {
            $header = strtolower(trim($header));

            if (in_array($header, ['payment number', 'payment_number', 'number', 'payment no'])) {
                $map['payment_number'] = $index;
            } elseif (in_array($header, ['vendor', 'vendor name', 'vendor_name', 'supplier', 'customer/vendor', 'customer\/vendor'])) {
                $map['vendor_name'] = $index;
            } elseif (in_array($header, ['bill', 'bill number', 'bill_number', 'invoice', 'invoice number', 'memo'])) {
                $map['bill_number'] = $index;
            } elseif (in_array($header, ['date', 'payment date', 'payment_date', 'paid date'])) {
                $map['payment_date'] = $index;
            } elseif (in_array($header, ['amount', 'payment amount', 'payment_amount', 'paid amount'])) {
                $map['amount'] = $index;
            } elseif (in_array($header, ['method', 'payment method', 'payment_method', 'pay method'])) {
                $map['payment_method'] = $index;
            } elseif (in_array($header, ['reference', 'ref', 'reference number', 'check number'])) {
                $map['reference'] = $index;
            } elseif (in_array($header, ['notes', 'description', 'remarks'])) {
                $map['memo'] = $index;
            }
        }

        return $map;
    }

    /**
     * Extract payment data from row
     */
    private function extractPaymentData(array $row, array $columnMap): array
    {
        // Get vendor
        $vendorName = isset($columnMap['vendor_name']) ? trim($row[$columnMap['vendor_name']]) : null;
        $vendor = null;

        if ($vendorName) {
            // Try exact match first (case-insensitive)
            $vendor = \App\Models\Vendor::whereRaw('LOWER(name) = ?', [strtolower($vendorName)])->first();

            // If no exact match, try partial match
            if (! $vendor) {
                $vendor = \App\Models\Vendor::where('name', 'LIKE', "%{$vendorName}%")->first();
            }
        }

        // Get bill if specified - try to match from memo column
        $billNumber = isset($columnMap['bill_number']) && ! empty($row[$columnMap['bill_number']])
            ? trim($row[$columnMap['bill_number']])
            : null;
        $bill = null;

        if ($billNumber && $vendor) {
            $bill = \App\Models\Bill::where('bill_number', $billNumber)
                ->where('vendor_id', $vendor->id)
                ->first();
        }

        // Parse payment date
        $paymentDate = null;
        if (isset($columnMap['payment_date']) && ! empty($row[$columnMap['payment_date']])) {
            $dateValue = $row[$columnMap['payment_date']];

            // Handle Excel date serial numbers
            if (is_numeric($dateValue)) {
                $paymentDate = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($dateValue)->format('Y-m-d');
            } else {
                $paymentDate = date('Y-m-d', strtotime($dateValue));
            }
        }

        // Parse amount - use the positive amount column (column 12 in the Excel)
        $amount = isset($columnMap['amount']) ? $row[$columnMap['amount']] : 0;
        if (is_string($amount)) {
            $amount = preg_replace('/[^0-9.-]/', '', $amount);
        }
        $amount = abs(floatval($amount)); // Use absolute value to ensure positive

        // Get payment method and normalize it
        $paymentMethodRaw = isset($columnMap['payment_method']) && ! empty($row[$columnMap['payment_method']])
            ? trim($row[$columnMap['payment_method']])
            : '';
        $paymentMethod = $this->normalizePaymentMethod($paymentMethodRaw);

        // Get current user
        $user = auth()->user();

        $data = [
            'vendor_id' => $vendor ? $vendor->id : null,
            'bill_id' => $bill ? $bill->id : null,
            'payment_date' => $paymentDate,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
            'reference' => isset($columnMap['reference']) && ! empty($row[$columnMap['reference']])
                ? trim($row[$columnMap['reference']])
                : null,
            'memo' => isset($columnMap['memo']) && ! empty($row[$columnMap['memo']])
                ? trim($row[$columnMap['memo']])
                : null,
            'created_by' => $user ? $user->id : null,
        ];

        // Generate payment number if not provided
        if (isset($columnMap['payment_number']) && ! empty($row[$columnMap['payment_number']])) {
            $data['payment_number'] = trim($row[$columnMap['payment_number']]);
        } else {
            $data['payment_number'] = \App\Models\BillPayment::generatePaymentNumber();
        }

        return $data;
    }

    /**
     * Normalize payment method to match database enum
     */
    private function normalizePaymentMethod(string $method): string
    {
        if (empty($method)) {
            return 'bank';
        }

        $method = strtolower(trim($method));

        // Map various payment method names to database enum values
        // Database allows: 'cash', 'bank', 'card', 'check', 'other'
        $methodMap = [
            'cash' => 'cash',
            'bank' => 'bank',
            'bank transfer' => 'bank',
            'bank_transfer' => 'bank',
            'wire transfer' => 'bank',
            'wire' => 'bank',
            'card' => 'card',
            'credit card' => 'card',
            'debit card' => 'card',
            'check' => 'check',
            'cheque' => 'check',
            'other' => 'other',
        ];

        // Check for direct match
        if (isset($methodMap[$method])) {
            return $methodMap[$method];
        }

        // Check if method contains keywords
        if (strpos($method, 'cash') !== false) {
            return 'cash';
        }
        if (strpos($method, 'bank') !== false || strpos($method, 'transfer') !== false) {
            return 'bank';
        }
        if (strpos($method, 'card') !== false || strpos($method, 'credit') !== false || strpos($method, 'debit') !== false) {
            return 'card';
        }
        if (strpos($method, 'check') !== false || strpos($method, 'cheque') !== false) {
            return 'check';
        }

        // Default to 'other' for unrecognized methods
        return 'other';
    }

    /**
     * Download import template
     */
    public function downloadTemplate(Request $request)
    {
        $request->validate([
            'type' => 'required|in:chart-of-accounts,journal-entries,vendor-bills,payments',
        ]);

        $templates = [
            'chart-of-accounts' => 'chart_of_accounts_template.xlsx',
            'journal-entries' => 'journal_entries_template.xlsx',
            'vendor-bills' => 'vendor_bills_template.xlsx',
            'payments' => 'payments_template.xlsx',
        ];

        $templateFile = $templates[$request->type];
        $templatePath = storage_path('app/templates/'.$templateFile);

        if (! file_exists($templatePath)) {
            return response()->json([
                'message' => 'Template not found',
                'error' => 'The template file does not exist',
            ], 404);
        }

        return response()->download($templatePath, $templateFile);
    }
}
