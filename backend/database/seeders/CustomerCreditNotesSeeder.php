<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CustomerCreditNotesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Get existing invoices and customers
        $invoices = DB::table('invoices')->limit(3)->get();
        $users = DB::table('users')->limit(1)->get();

        if ($invoices->isEmpty() || $users->isEmpty()) {
            $this->command->warn('No invoices or users found. Skipping customer credit notes seeder.');

            return;
        }

        $userId = $users->first()->id;
        $creditNotes = [];

        // Create 5 sample customer credit notes
        foreach ($invoices as $index => $invoice) {
            // Get invoice lines
            $invoiceLines = DB::table('invoice_lines')
                ->where('invoice_id', $invoice->id)
                ->get();

            if ($invoiceLines->isEmpty()) {
                continue;
            }

            // Create credit note
            $creditNoteId = DB::table('customer_credit_notes')->insertGetId([
                'credit_note_number' => 'CN-2026-'.str_pad($index + 1, 4, '0', STR_PAD_LEFT),
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->id,
                'credit_note_date' => Carbon::now()->subDays(rand(1, 30))->format('Y-m-d'),
                'due_date' => null,
                'status' => $index < 2 ? 'posted' : 'draft', // First 2 posted, rest draft
                'subtotal' => 0, // Will be calculated
                'tax_amount' => 0, // Will be calculated
                'total_amount' => 0, // Will be calculated
                'currency_code' => 'USD',
                'reason' => $this->getRandomReason(),
                'notes' => 'Sample credit note for testing purposes',
                'journal_entry_id' => null,
                'created_by' => $userId,
                'posted_by' => $index < 2 ? $userId : null,
                'posted_at' => $index < 2 ? Carbon::now()->subDays(rand(1, 20))->format('Y-m-d H:i:s') : null,
                'created_at' => Carbon::now()->subDays(rand(1, 30))->format('Y-m-d H:i:s'),
                'updated_at' => Carbon::now()->subDays(rand(1, 30))->format('Y-m-d H:i:s'),
            ]);

            // Create credit note lines (partial credit - 50% of invoice)
            $subtotal = 0;
            $taxAmount = 0;

            foreach ($invoiceLines as $lineIndex => $invoiceLine) {
                if ($lineIndex >= 2) {
                    break;
                } // Only credit first 2 lines

                $quantity = $invoiceLine->quantity * 0.5; // 50% of quantity
                $unitPrice = $invoiceLine->unit_price;
                $lineTotal = $quantity * $unitPrice;
                $lineTax = $lineTotal * ($invoiceLine->tax_rate ?? 0) / 100;

                DB::table('customer_credit_note_lines')->insert([
                    'credit_note_id' => $creditNoteId,
                    'invoice_line_id' => $invoiceLine->id,
                    'product_id' => $invoiceLine->product_id,
                    'description' => $invoiceLine->description.' (Partial Return)',
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'tax_rate' => $invoiceLine->tax_rate ?? 0,
                    'line_total' => $lineTotal,
                    'account_id' => null,
                    'created_at' => Carbon::now()->format('Y-m-d H:i:s'),
                    'updated_at' => Carbon::now()->format('Y-m-d H:i:s'),
                ]);

                $subtotal += $lineTotal;
                $taxAmount += $lineTax;
            }

            // Update credit note totals
            $totalAmount = $subtotal + $taxAmount;
            DB::table('customer_credit_notes')
                ->where('id', $creditNoteId)
                ->update([
                    'subtotal' => $subtotal,
                    'tax_amount' => $taxAmount,
                    'total_amount' => $totalAmount,
                ]);

            $creditNotes[] = [
                'id' => $creditNoteId,
                'number' => 'CN-2026-'.str_pad($index + 1, 4, '0', STR_PAD_LEFT),
                'status' => $index < 2 ? 'posted' : 'draft',
                'total' => $totalAmount,
            ];
        }

        $this->command->info('Created '.count($creditNotes).' customer credit notes:');
        foreach ($creditNotes as $cn) {
            $this->command->info("  - {$cn['number']} ({$cn['status']}): $".number_format($cn['total'], 2));
        }
    }

    /**
     * Get a random reason for credit note
     *
     * @return string
     */
    private function getRandomReason()
    {
        $reasons = [
            'Product return - customer not satisfied',
            'Billing error - overcharged',
            'Damaged goods received',
            'Partial delivery - missing items',
            'Price adjustment per agreement',
            'Quality issue - defective product',
            'Wrong product shipped',
            'Customer cancellation',
        ];

        return $reasons[array_rand($reasons)];
    }
}
