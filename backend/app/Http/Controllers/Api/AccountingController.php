<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use Illuminate\Http\Request;

class AccountingController extends Controller
{
    public function __construct(
        private AccountingService $accountingService
    ) {}

    public function chartOfAccounts()
    {
        $accounts = ChartOfAccount::where('is_active', true)
            ->orderBy('code')
            ->get()
            ->map(function ($account) {
                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'subtype' => $account->subtype,
                    'balance' => $account->getBalance(),
                    'is_system' => $account->is_system,
                ];
            });

        return response()->json($accounts);
    }

    public function journalEntries()
    {
        $entries = JournalEntry::with(['lines.account', 'createdBy'])
            ->where('status', 'posted')
            ->orderBy('entry_date', 'desc')
            ->paginate(50);

        return response()->json($entries);
    }

    public function trialBalance()
    {
        $trialBalance = $this->accountingService->getTrialBalance();

        return response()->json($trialBalance);
    }

    public function ledger($accountId)
    {
        try {
            $ledger = $this->accountingService->getAccountLedger((int) $accountId);

            return response()->json($ledger);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve ledger',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
