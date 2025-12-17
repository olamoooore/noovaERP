<?php

namespace Modules\POS\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\PosOrder;

class PosPostingService
{
    public function postPaidOrder(PosOrder $order, float $totalPaid, string $paymentMethod): JournalEntry
    {
        return DB::transaction(function () use ($order, $totalPaid, $paymentMethod) {
            $cashAccount = ChartOfAccount::query()->where('code', '1010')->firstOrFail();
            $salesAccount = ChartOfAccount::query()->where('code', '4100')->firstOrFail();
            $taxAccount = ChartOfAccount::query()->where('code', '2200')->firstOrFail();
            $inventoryAccount = ChartOfAccount::query()->where('code', '1200')->firstOrFail();
            $cogsAccount = ChartOfAccount::query()->where('code', '5100')->firstOrFail();

            $entry = JournalEntry::create([
                'entry_number' => $this->makeEntryNumber($order),
                'entry_date' => now()->toDateString(),
                'type' => 'payment',
                'reference' => $order->order_number,
                'description' => 'POS sale (' . $paymentMethod . ')',
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);

            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => $cashAccount->id,
                'debit' => $totalPaid,
                'credit' => 0,
                'description' => 'POS payment received',
                'reference' => $order->order_number,
            ]);

            if ((float) $order->subtotal > 0) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $salesAccount->id,
                    'debit' => 0,
                    'credit' => (float) $order->subtotal,
                    'description' => 'POS sales revenue',
                    'reference' => $order->order_number,
                ]);
            }

            if ((float) $order->tax_amount > 0) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $taxAccount->id,
                    'debit' => 0,
                    'credit' => (float) $order->tax_amount,
                    'description' => 'POS sales tax',
                    'reference' => $order->order_number,
                ]);
            }

            $totalCost = 0.0;
            foreach ($order->items as $item) {
                $cost = 0.0;
                if ($item->product_variant_id && $item->variant) {
                    $cost = (float) $item->variant->cost;
                } elseif ($item->product_id && $item->product) {
                    $cost = (float) $item->product->cost;
                }
                $totalCost += $cost * (int) $item->quantity;
            }

            if ($totalCost > 0) {
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

            $entry->post();

            return $entry;
        });
    }

    private function makeEntryNumber(PosOrder $order): string
    {
        $stamp = now()->format('YmdHis');
        return 'JE-POS-' . $stamp . '-' . $order->id;
    }
}
