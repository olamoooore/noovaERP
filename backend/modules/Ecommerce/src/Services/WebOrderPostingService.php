<?php

namespace Modules\Ecommerce\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Ecommerce\Models\WebOrder;

class WebOrderPostingService
{
    /**
     * Post a paid web order to the accounting system.
     * Creates journal entries for revenue, tax, COGS, and inventory.
     */
    public function postPaidOrder(WebOrder $order, float $totalPaid, string $paymentMethod = 'card'): ?JournalEntry
    {
        // Only post confirmed/paid orders
        if ($order->payment_status !== 'paid' || $order->status !== 'confirmed') {
            Log::warning('Cannot post unpaid/unconfirmed web order', [
                'order_id' => $order->id,
                'payment_status' => $order->payment_status,
                'status' => $order->status,
            ]);
            return null;
        }
        
        // Check if already posted
        $existingEntry = JournalEntry::where('reference', $order->order_number)
            ->where('type', 'payment')
            ->first();
            
        if ($existingEntry) {
            Log::info('Web order already posted', [
                'order_id' => $order->id,
                'entry_id' => $existingEntry->id,
            ]);
            return $existingEntry;
        }
        
        return DB::transaction(function () use ($order, $totalPaid, $paymentMethod) {
            // Get accounts
            $cashAccount = ChartOfAccount::query()->where('code', '1010')->first();
            $salesAccount = ChartOfAccount::query()->where('code', '4100')->first();
            $taxAccount = ChartOfAccount::query()->where('code', '2200')->first();
            $inventoryAccount = ChartOfAccount::query()->where('code', '1200')->first();
            $cogsAccount = ChartOfAccount::query()->where('code', '5100')->first();
            $discountAccount = ChartOfAccount::query()->where('code', '4200')->first(); // Sales discounts
            
            if (!$cashAccount || !$salesAccount) {
                throw new \Exception('Required accounts not found. Please ensure chart of accounts is set up.');
            }

            $entry = JournalEntry::create([
                'entry_number' => $this->makeEntryNumber($order),
                'entry_date' => $order->paid_at?->toDateString() ?? now()->toDateString(),
                'type' => 'payment',
                'reference' => $order->order_number,
                'description' => 'Web sale (' . $paymentMethod . ')',
                'status' => 'draft',
                'created_by' => auth()->id() ?? 1,
            ]);

            // Debit cash/bank account
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $cashAccount->id,
                'debit' => $totalPaid,
                'credit' => 0,
                'description' => 'Web payment received',
                'reference' => $order->order_number,
            ]);

            // Credit sales revenue
            if ((float) $order->subtotal > 0) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $salesAccount->id,
                    'debit' => 0,
                    'credit' => (float) $order->subtotal,
                    'description' => 'Web sales revenue',
                    'reference' => $order->order_number,
                ]);
            }

            // Credit tax payable
            if ((float) $order->tax_amount > 0) {
                if ($taxAccount) {
                    JournalEntryLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $taxAccount->id,
                        'debit' => 0,
                        'credit' => (float) $order->tax_amount,
                        'description' => 'Web sales tax',
                        'reference' => $order->order_number,
                    ]);
                }
            }
            
            // Debit discount account for loyalty discounts
            if ((float) ($order->loyalty_discount ?? 0) > 0 && $discountAccount) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $discountAccount->id,
                    'debit' => (float) $order->loyalty_discount,
                    'credit' => 0,
                    'description' => 'Loyalty discount',
                    'reference' => $order->order_number,
                ]);
            }

            // Calculate and post COGS
            $totalCost = 0.0;
            foreach ($order->items as $item) {
                $cost = 0.0;
                if ($item->product) {
                    $cost = (float) $item->product->cost;
                }
                $totalCost += $cost * (int) $item->quantity;
            }

            if ($totalCost > 0 && $cogsAccount && $inventoryAccount) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $cogsAccount->id,
                    'debit' => $totalCost,
                    'credit' => 0,
                    'description' => 'Cost of goods sold',
                    'reference' => $order->order_number,
                ]);

                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $inventoryAccount->id,
                    'debit' => 0,
                    'credit' => $totalCost,
                    'description' => 'Inventory reduction',
                    'reference' => $order->order_number,
                ]);
            }

            // Post the entry
            $entry->post();
            
            Log::info('Web order posted to accounting', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'entry_id' => $entry->id,
                'entry_number' => $entry->entry_number,
            ]);

            return $entry;
        });
    }
    
    /**
     * Reverse a web order posting (for refunds/cancellations).
     */
    public function reverseOrderPosting(WebOrder $order, string $reason = 'Order cancelled/refunded'): ?JournalEntry
    {
        // Find the original entry
        $originalEntry = JournalEntry::where('reference', $order->order_number)
            ->where('type', 'payment')
            ->first();
            
        if (!$originalEntry) {
            Log::info('No journal entry found to reverse for web order', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);
            return null;
        }
        
        return DB::transaction(function () use ($order, $originalEntry, $reason) {
            // Create reversal entry
            $entry = JournalEntry::create([
                'entry_number' => 'JE-WEB-REV-' . now()->format('YmdHis') . '-' . $order->id,
                'entry_date' => now()->toDateString(),
                'type' => 'adjustment',
                'reference' => $order->order_number . '-REV',
                'description' => $reason,
                'status' => 'draft',
                'created_by' => auth()->id() ?? 1,
            ]);
            
            // Reverse all lines from original entry
            foreach ($originalEntry->lines as $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line->account_id,
                    'debit' => $line->credit, // Swap debit/credit
                    'credit' => $line->debit,
                    'description' => 'Reversal: ' . $line->description,
                    'reference' => $order->order_number,
                ]);
            }
            
            // Post the reversal entry
            $entry->post();
            
            Log::info('Web order posting reversed', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'reversal_entry_id' => $entry->id,
            ]);
            
            return $entry;
        });
    }

    private function makeEntryNumber(WebOrder $order): string
    {
        $stamp = now()->format('YmdHis');
        return 'JE-WEB-' . $stamp . '-' . $order->id;
    }
}
