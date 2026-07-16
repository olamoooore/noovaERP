<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VendorCreditNotesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Get existing bills and vendors
        $bills = DB::table('bills')->limit(2)->get();
        $users = DB::table('users')->limit(1)->get();

        if ($bills->isEmpty() || $users->isEmpty()) {
            $this->command->warn('No bills or users found. Skipping vendor credit notes seeder.');

            return;
        }

        $userId = $users->first()->id;
        $creditNotes = [];

        // Create 5 sample vendor credit notes (some bills may have multiple credit notes)
        $creditNoteCount = 0;
        foreach ($bills as $billIndex => $bill) {
            // Get bill lines
            $billLines = DB::table('bill_lines')
                ->where('bill_id', $bill->id)
                ->get();

            if ($billLines->isEmpty()) {
                continue;
            }

            // Create 2-3 credit notes per bill
            $creditNotesPerBill = $billIndex === 0 ? 3 : 2;

            for ($i = 0; $i < $creditNotesPerBill && $creditNoteCount < 5; $i++) {
                $creditNoteCount++;

                // Create credit note
                $creditNoteId = DB::table('vendor_credit_notes')->insertGetId([
                    'credit_note_number' => 'VCN-2026-'.str_pad($creditNoteCount, 4, '0', STR_PAD_LEFT),
                    'vendor_id' => $bill->vendor_id,
                    'bill_id' => $bill->id,
                    'credit_note_date' => Carbon::now()->subDays(rand(1, 30))->format('Y-m-d'),
                    'due_date' => null,
                    'status' => $creditNoteCount <= 2 ? 'posted' : 'draft', // First 2 posted, rest draft
                    'subtotal' => 0, // Will be calculated
                    'tax_amount' => 0, // Will be calculated
                    'total_amount' => 0, // Will be calculated
                    'currency_code' => 'USD',
                    'reason' => $this->getRandomReason(),
                    'notes' => 'Sample vendor credit note for testing purposes',
                    'journal_entry_id' => null,
                    'created_by' => $userId,
                    'posted_by' => $creditNoteCount <= 2 ? $userId : null,
                    'posted_at' => $creditNoteCount <= 2 ? Carbon::now()->subDays(rand(1, 20))->format('Y-m-d H:i:s') : null,
                    'created_at' => Carbon::now()->subDays(rand(1, 30))->format('Y-m-d H:i:s'),
                    'updated_at' => Carbon::now()->subDays(rand(1, 30))->format('Y-m-d H:i:s'),
                ]);

                // Create credit note lines (varying percentages)
                $subtotal = 0;
                $taxAmount = 0;
                $creditPercentage = [0.3, 0.5, 0.25][$i % 3]; // 30%, 50%, or 25%

                foreach ($billLines as $lineIndex => $billLine) {
                    if ($lineIndex >= 2) {
                        break;
                    } // Only credit first 2 lines

                    $quantity = $billLine->quantity * $creditPercentage;
                    $unitPrice = $billLine->unit_price;
                    $lineTotal = $quantity * $unitPrice;
                    $lineTax = $lineTotal * ($billLine->tax_rate ?? 0) / 100;

                    DB::table('vendor_credit_note_lines')->insert([
                        'credit_note_id' => $creditNoteId,
                        'bill_line_id' => $billLine->id,
                        'product_id' => $billLine->product_id ?? null,
                        'description' => $billLine->description.' (Vendor Credit)',
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'tax_rate' => $billLine->tax_rate ?? 0,
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
                DB::table('vendor_credit_notes')
                    ->where('id', $creditNoteId)
                    ->update([
                        'subtotal' => $subtotal,
                        'tax_amount' => $taxAmount,
                        'total_amount' => $totalAmount,
                    ]);

                $creditNotes[] = [
                    'id' => $creditNoteId,
                    'number' => 'VCN-2026-'.str_pad($creditNoteCount, 4, '0', STR_PAD_LEFT),
                    'status' => $creditNoteCount <= 2 ? 'posted' : 'draft',
                    'total' => $totalAmount,
                ];
            }
        }

        $this->command->info('Created '.count($creditNotes).' vendor credit notes:');
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
            'Incorrect billing - overcharged',
            'Damaged goods returned to vendor',
            'Quality issue - defective items',
            'Partial delivery - missing items',
            'Price adjustment per contract',
            'Wrong items received',
            'Vendor error - duplicate billing',
            'Service not rendered as agreed',
        ];

        return $reasons[array_rand($reasons)];
    }
}
