<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SampleTransactionsSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Creating sample journal entries...');

        $userId = DB::table('users')->first()->id;
        $cashAccount = DB::table('chart_of_accounts')->where('code', '1010')->first();
        $revenueAccount = DB::table('chart_of_accounts')->where('type', 'revenue')->first();
        $expenseAccount = DB::table('chart_of_accounts')->where('type', 'expense')->first();
        $arAccount = DB::table('chart_of_accounts')->where('code', '1020')->first();
        $apAccount = DB::table('chart_of_accounts')->where('type', 'liability')->first();
        $journalId = DB::table('journals')->where('code', 'GEN')->first()->id;

        // Create 10 sample journal entries
        for ($i = 1; $i <= 10; $i++) {
            $entryDate = Carbon::now()->subDays(rand(1, 60));

            $entryId = DB::table('journal_entries')->insertGetId([
                'entry_number' => 'JE-2026-'.str_pad($i, 4, '0', STR_PAD_LEFT),
                'journal_id' => $journalId,
                'entry_date' => $entryDate,
                'reference' => 'Sample Entry '.$i,
                'description' => 'Sample transaction for testing reports',
                'status' => 'posted',
                'created_by' => $userId,
                'created_at' => $entryDate,
                'updated_at' => $entryDate,
            ]);

            $amount = rand(500, 5000);

            // Alternate between revenue and expense entries
            if ($i % 2 == 0) {
                // Revenue entry: Debit Cash, Credit Revenue
                DB::table('journal_entry_lines')->insert([
                    [
                        'journal_entry_id' => $entryId,
                        'account_id' => $cashAccount->id,
                        'debit' => $amount,
                        'credit' => 0,
                        'description' => 'Cash received from sales',
                        'created_at' => $entryDate,
                        'updated_at' => $entryDate,
                    ],
                    [
                        'journal_entry_id' => $entryId,
                        'account_id' => $revenueAccount->id,
                        'debit' => 0,
                        'credit' => $amount,
                        'description' => 'Revenue earned',
                        'created_at' => $entryDate,
                        'updated_at' => $entryDate,
                    ],
                ]);
            } else {
                // Expense entry: Debit Expense, Credit Cash
                DB::table('journal_entry_lines')->insert([
                    [
                        'journal_entry_id' => $entryId,
                        'account_id' => $expenseAccount->id,
                        'debit' => $amount,
                        'credit' => 0,
                        'description' => 'Business expense',
                        'created_at' => $entryDate,
                        'updated_at' => $entryDate,
                    ],
                    [
                        'journal_entry_id' => $entryId,
                        'account_id' => $cashAccount->id,
                        'debit' => 0,
                        'credit' => $amount,
                        'description' => 'Cash paid',
                        'created_at' => $entryDate,
                        'updated_at' => $entryDate,
                    ],
                ]);
            }
        }

        $this->command->info('✓ Created 10 journal entries with 20 lines');

        // Create sample invoices
        $this->command->info('Creating sample invoices...');
        $customers = DB::table('customers')->get();
        $products = DB::table('products')->get();

        if ($customers->isNotEmpty() && $products->isNotEmpty()) {
            foreach ($customers as $index => $customer) {
                $invoiceDate = Carbon::now()->subDays(rand(1, 45));
                $dueDate = $invoiceDate->copy()->addDays(30);

                $invoiceId = DB::table('invoices')->insertGetId([
                    'invoice_number' => 'INV-2026-'.str_pad($index + 1, 4, '0', STR_PAD_LEFT),
                    'customer_id' => $customer->id,
                    'invoice_date' => $invoiceDate,
                    'due_date' => $dueDate,
                    'status' => rand(0, 1) ? 'paid' : 'sent',
                    'subtotal' => 0,
                    'tax_amount' => 0,
                    'total' => 0,
                    'paid_amount' => 0,
                    'balance' => 0,
                    'created_by' => $userId,
                    'created_at' => $invoiceDate,
                    'updated_at' => $invoiceDate,
                ]);

                $product = $products->random();
                $quantity = rand(1, 5);
                $unitPrice = $product->price;
                $lineTotal = $quantity * $unitPrice;
                $taxAmount = $lineTotal * 0.10;
                $total = $lineTotal + $taxAmount;

                DB::table('invoice_lines')->insert([
                    'invoice_id' => $invoiceId,
                    'product_id' => $product->id,
                    'description' => $product->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'tax_rate' => 10,
                    'tax_amount' => $taxAmount,
                    'line_total' => $lineTotal,
                    'created_at' => $invoiceDate,
                    'updated_at' => $invoiceDate,
                ]);

                $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
                DB::table('invoices')->where('id', $invoiceId)->update([
                    'subtotal' => $lineTotal,
                    'tax_amount' => $taxAmount,
                    'total' => $total,
                    'paid_amount' => $invoice->status === 'paid' ? $total : 0,
                    'balance' => $invoice->status === 'paid' ? 0 : $total,
                ]);
            }

            $this->command->info('✓ Created '.$customers->count().' invoices');
        }

        // Create sample bills
        $this->command->info('Creating sample bills...');
        $vendors = DB::table('vendors')->get();

        if ($vendors->isNotEmpty() && $products->isNotEmpty()) {
            foreach ($vendors as $index => $vendor) {
                $billDate = Carbon::now()->subDays(rand(1, 45));
                $dueDate = $billDate->copy()->addDays(30);

                $billId = DB::table('bills')->insertGetId([
                    'bill_number' => 'BILL-2026-'.str_pad($index + 1, 4, '0', STR_PAD_LEFT),
                    'vendor_id' => $vendor->id,
                    'bill_date' => $billDate,
                    'due_date' => $dueDate,
                    'status' => rand(0, 1) ? 'paid' : 'pending',
                    'subtotal' => 0,
                    'tax_amount' => 0,
                    'total' => 0,
                    'paid_amount' => 0,
                    'balance' => 0,
                    'created_by' => $userId,
                    'created_at' => $billDate,
                    'updated_at' => $billDate,
                ]);

                $product = $products->random();
                $quantity = rand(1, 10);
                $unitPrice = $product->cost;
                $lineTotal = $quantity * $unitPrice;
                $taxAmount = $lineTotal * 0.10;
                $total = $lineTotal + $taxAmount;

                DB::table('bill_lines')->insert([
                    'bill_id' => $billId,
                    'product_id' => $product->id,
                    'description' => $product->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'tax_rate' => 10,
                    'tax_amount' => $taxAmount,
                    'line_total' => $lineTotal,
                    'created_at' => $billDate,
                    'updated_at' => $billDate,
                ]);

                $bill = DB::table('bills')->where('id', $billId)->first();
                DB::table('bills')->where('id', $billId)->update([
                    'subtotal' => $lineTotal,
                    'tax_amount' => $taxAmount,
                    'total' => $total,
                    'paid_amount' => $bill->status === 'paid' ? $total : 0,
                    'balance' => $bill->status === 'paid' ? 0 : $total,
                ]);
            }

            $this->command->info('✓ Created '.$vendors->count().' bills');
        }

        $this->command->info('Sample transactions seeded successfully!');
    }
}
