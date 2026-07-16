<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Bank Accounts Controller
 * Manages bank account setup and configuration
 */
class BankAccountsController extends Controller
{
    /**
     * List all bank accounts
     */
    public function index(Request $request)
    {
        $query = DB::table('bank_accounts')
            ->leftJoin('chart_of_accounts', 'bank_accounts.gl_account_id', '=', 'chart_of_accounts.id')
            ->select(
                'bank_accounts.*',
                'chart_of_accounts.name as gl_account_name',
                'chart_of_accounts.code as gl_account_code'
            );

        // Filter by active status
        if ($request->has('is_active')) {
            $query->where('bank_accounts.is_active', $request->boolean('is_active'));
        }

        // Search by account name or bank name
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('bank_accounts.account_name', 'LIKE', "%{$search}%")
                    ->orWhere('bank_accounts.bank_name', 'LIKE', "%{$search}%")
                    ->orWhere('bank_accounts.account_number', 'LIKE', "%{$search}%");
            });
        }

        $bankAccounts = $query->orderBy('bank_accounts.account_name')->get();

        return response()->json(['data' => $bankAccounts]);
    }

    /**
     * Get a specific bank account
     */
    public function show($id)
    {
        $bankAccount = DB::table('bank_accounts')
            ->leftJoin('chart_of_accounts', 'bank_accounts.gl_account_id', '=', 'chart_of_accounts.id')
            ->where('bank_accounts.id', $id)
            ->select(
                'bank_accounts.*',
                'chart_of_accounts.name as gl_account_name',
                'chart_of_accounts.code as gl_account_code'
            )
            ->first();

        if (! $bankAccount) {
            return response()->json(['error' => 'Bank account not found'], 404);
        }

        return response()->json(['data' => $bankAccount]);
    }

    /**
     * Create a new bank account
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'account_name' => 'required|string|max:255',
            'account_number' => 'nullable|string|max:50',
            'bank_name' => 'nullable|string|max:255',
            'currency_code' => 'required|string|max:3|exists:currencies,code',
            'gl_account_id' => 'required|integer|exists:chart_of_accounts,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if account number already exists
        if ($request->account_number) {
            $exists = DB::table('bank_accounts')
                ->where('account_number', $request->account_number)
                ->where('bank_name', $request->bank_name)
                ->exists();

            if ($exists) {
                return response()->json([
                    'error' => 'Bank account with this account number already exists',
                ], 422);
            }
        }

        $bankAccountId = DB::table('bank_accounts')->insertGetId([
            'account_name' => $request->account_name,
            'account_number' => $request->account_number,
            'bank_name' => $request->bank_name,
            'currency_code' => $request->currency_code,
            'gl_account_id' => $request->gl_account_id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bankAccount = DB::table('bank_accounts')->where('id', $bankAccountId)->first();

        return response()->json([
            'message' => 'Bank account created successfully',
            'data' => $bankAccount,
        ], 201);
    }

    /**
     * Update a bank account
     */
    public function update(Request $request, $id)
    {
        $bankAccount = DB::table('bank_accounts')->where('id', $id)->first();

        if (! $bankAccount) {
            return response()->json(['error' => 'Bank account not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'account_name' => 'sometimes|string|max:255',
            'account_number' => 'nullable|string|max:50',
            'bank_name' => 'nullable|string|max:255',
            'currency_code' => 'sometimes|string|max:3|exists:currencies,code',
            'gl_account_id' => 'sometimes|integer|exists:chart_of_accounts,id',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Check if account number is unique (excluding current record)
        if ($request->has('account_number') && $request->account_number) {
            $exists = DB::table('bank_accounts')
                ->where('account_number', $request->account_number)
                ->where('bank_name', $request->bank_name ?? $bankAccount->bank_name)
                ->where('id', '!=', $id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'error' => 'Bank account with this account number already exists',
                ], 422);
            }
        }

        $updateData = [];
        if ($request->has('account_name')) {
            $updateData['account_name'] = $request->account_name;
        }
        if ($request->has('account_number')) {
            $updateData['account_number'] = $request->account_number;
        }
        if ($request->has('bank_name')) {
            $updateData['bank_name'] = $request->bank_name;
        }
        if ($request->has('currency_code')) {
            $updateData['currency_code'] = $request->currency_code;
        }
        if ($request->has('gl_account_id')) {
            $updateData['gl_account_id'] = $request->gl_account_id;
        }
        if ($request->has('is_active')) {
            $updateData['is_active'] = $request->is_active;
        }
        $updateData['updated_at'] = now();

        DB::table('bank_accounts')->where('id', $id)->update($updateData);

        $updatedBankAccount = DB::table('bank_accounts')->where('id', $id)->first();

        return response()->json([
            'message' => 'Bank account updated successfully',
            'data' => $updatedBankAccount,
        ]);
    }

    /**
     * Delete (deactivate) a bank account
     */
    public function destroy($id)
    {
        $bankAccount = DB::table('bank_accounts')->where('id', $id)->first();

        if (! $bankAccount) {
            return response()->json(['error' => 'Bank account not found'], 404);
        }

        // Check if bank account has transactions
        $hasTransactions = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entry_lines.account_id', $bankAccount->gl_account_id)
            ->exists();

        if ($hasTransactions) {
            return response()->json([
                'error' => 'Cannot delete bank account with transactions. Deactivate it instead.',
            ], 422);
        }

        // Soft delete by deactivating
        DB::table('bank_accounts')
            ->where('id', $id)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Bank account deactivated successfully',
        ]);
    }

    /**
     * Get bank account balance
     */
    public function getBalance($id)
    {
        $bankAccount = DB::table('bank_accounts')->where('id', $id)->first();

        if (! $bankAccount) {
            return response()->json(['error' => 'Bank account not found'], 404);
        }

        // Calculate balance from journal entries
        $balance = DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entry_lines.account_id', $bankAccount->gl_account_id)
            ->where('journal_entries.status', 'posted')
            ->selectRaw('SUM(debit - credit) as balance')
            ->first();

        $currentBalance = $balance->balance ?? 0;

        return response()->json([
            'data' => [
                'current_balance' => $currentBalance,
                'currency_code' => $bankAccount->currency_code,
            ],
        ]);
    }
}
