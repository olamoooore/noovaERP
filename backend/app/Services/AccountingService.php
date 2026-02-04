<?php

namespace App\Services;

use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;

/**
 * Accounting Service - Double-Entry Ledger Core
 * Handles all journal entry creation and posting
 * Enforces debit = credit rule
 */
class AccountingService
{
    /**
     * Create journal entry (auto-balancing validation)
     */
    public function createJournalEntry(array $data, array $lines): JournalEntry
    {
        DB::beginTransaction();
        
        try {
            // Validate balance
            $totalDebit = collect($lines)->sum('debit');
            $totalCredit = collect($lines)->sum('credit');
            
            if (abs($totalDebit - $totalCredit) > 0.01) {
                throw new \Exception("Journal entry must balance. Debit: {$totalDebit}, Credit: {$totalCredit}");
            }

            // Generate entry number
            $data['entry_number'] = $this->generateEntryNumber();
            $data['created_by'] = auth()->id();
            $data['status'] = $data['status'] ?? 'draft';

            $entry = JournalEntry::create($data);

            foreach ($lines as $line) {
                $entry->lines()->create([
                    'account_id' => $line['account_id'],
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                    'description' => $line['description'] ?? null,
                    'reference' => $line['reference'] ?? null,
                ]);
            }

            DB::commit();
            
            return $entry->fresh('lines');
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Post invoice to ledger
     * DR: Accounts Receivable
     * CR: Sales Revenue
     * CR: Sales Tax Payable
     */
    public function postInvoice($invoice): JournalEntry
    {
        $arAccount = ChartOfAccount::where('code', '1100')->first(); // A/R
        $revenueAccount = ChartOfAccount::where('code', '4100')->first(); // Sales
        $taxAccount = ChartOfAccount::where('code', '2200')->first(); // Tax Payable

        $lines = [
            [
                'account_id' => $arAccount->id,
                'debit' => $invoice->total,
                'credit' => 0,
                'description' => "Invoice {$invoice->invoice_number}",
            ],
            [
                'account_id' => $revenueAccount->id,
                'debit' => 0,
                'credit' => $invoice->subtotal,
                'description' => "Sales - Invoice {$invoice->invoice_number}",
            ],
        ];

        if ($invoice->tax_amount > 0) {
            $lines[] = [
                'account_id' => $taxAccount->id,
                'debit' => 0,
                'credit' => $invoice->tax_amount,
                'description' => "Tax - Invoice {$invoice->invoice_number}",
            ];
        }

        return $this->createJournalEntry([
            'entry_date' => $invoice->invoice_date,
            'type' => 'invoice',
            'reference' => $invoice->invoice_number,
            'description' => "Invoice #{$invoice->invoice_number} - {$invoice->customer->name}",
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => auth()->id(),
        ], $lines);
    }

    /**
     * Post payment to ledger
     * DR: Cash/Bank
     * CR: Accounts Receivable
     */
    public function postPayment($payment): JournalEntry
    {
        $cashAccount = ChartOfAccount::where('code', '1010')->first(); // Cash
        $arAccount = ChartOfAccount::where('code', '1100')->first(); // A/R

        $lines = [
            [
                'account_id' => $cashAccount->id,
                'debit' => $payment->amount,
                'credit' => 0,
                'description' => "Payment {$payment->payment_number}",
            ],
            [
                'account_id' => $arAccount->id,
                'debit' => 0,
                'credit' => $payment->amount,
                'description' => "Payment received - {$payment->payment_number}",
            ],
        ];

        return $this->createJournalEntry([
            'entry_date' => $payment->payment_date,
            'type' => 'payment',
            'reference' => $payment->payment_number,
            'description' => "Payment #{$payment->payment_number} - {$payment->customer->name}",
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => auth()->id(),
        ], $lines);
    }

    /**
     * Get trial balance (all accounts with balances)
     */
    public function getTrialBalance(): array
    {
        $accounts = ChartOfAccount::where('is_active', DB::raw('true'))
            ->orderBy('code')
            ->get();

        $trialBalance = [];
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($accounts as $account) {
            $balance = $account->getBalance();
            
            if (abs($balance) > 0.01) {
                $isDebit = in_array($account->type, ['asset', 'expense']);
                
                $trialBalance[] = [
                    'account_code' => $account->code,
                    'account_name' => $account->name,
                    'account_type' => $account->type,
                    'debit' => $isDebit && $balance > 0 ? $balance : 0,
                    'credit' => !$isDebit && $balance > 0 ? $balance : 0,
                ];

                if ($isDebit && $balance > 0) {
                    $totalDebit += $balance;
                } elseif (!$isDebit && $balance > 0) {
                    $totalCredit += $balance;
                }
            }
        }

        return [
            'accounts' => $trialBalance,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'is_balanced' => abs($totalDebit - $totalCredit) < 0.01,
        ];
    }

    /**
     * Get account ledger (transaction history)
     */
    public function getAccountLedger(int $accountId): array
    {
        $account = ChartOfAccount::findOrFail($accountId);
        
        $lines = JournalEntryLine::where('account_id', $accountId)
            ->whereHas('journalEntry', fn($q) => $q->where('status', 'posted'))
            ->with(['journalEntry' => function($q) {
                $q->orderBy('entry_date')->orderBy('id');
            }])
            ->get();

        $runningBalance = (float) $account->opening_balance;
        $transactions = [];

        foreach ($lines as $line) {
            $isDebit = in_array($account->type, ['asset', 'expense']);
            
            if ($isDebit) {
                $runningBalance += (float) $line->debit - (float) $line->credit;
            } else {
                $runningBalance += (float) $line->credit - (float) $line->debit;
            }

            $transactions[] = [
                'date' => $line->journalEntry->entry_date->format('Y-m-d'),
                'entry_number' => $line->journalEntry->entry_number,
                'description' => $line->description ?? $line->journalEntry->description,
                'reference' => $line->reference ?? $line->journalEntry->reference,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'balance' => $runningBalance,
            ];
        }

        return [
            'account' => $account,
            'opening_balance' => (float) $account->opening_balance,
            'transactions' => $transactions,
            'closing_balance' => $runningBalance,
        ];
    }

    private function generateEntryNumber(): string
    {
        $latest = JournalEntry::orderBy('id', 'desc')->first();
        $number = $latest ? (int) substr($latest->entry_number, -4) + 1 : 1;
        return 'JE-' . date('Y') . '-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }
}
