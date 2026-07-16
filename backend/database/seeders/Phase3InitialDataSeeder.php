<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class Phase3InitialDataSeeder extends Seeder
{
    public function run(): void
    {
        // Seed default journal types
        $journals = [
            ['code' => 'SAL', 'name' => 'Sales Journal', 'type' => 'Sales', 'sequence_prefix' => 'SAL', 'sequence_number' => 1, 'is_active' => true],
            ['code' => 'PUR', 'name' => 'Purchase Journal', 'type' => 'Purchases', 'sequence_prefix' => 'PUR', 'sequence_number' => 1, 'is_active' => true],
            ['code' => 'CASH', 'name' => 'Cash Journal', 'type' => 'Cash', 'sequence_prefix' => 'CASH', 'sequence_number' => 1, 'is_active' => true],
            ['code' => 'BANK', 'name' => 'Bank Journal', 'type' => 'Bank', 'sequence_prefix' => 'BANK', 'sequence_number' => 1, 'is_active' => true],
            ['code' => 'GEN', 'name' => 'General Journal', 'type' => 'General', 'sequence_prefix' => 'GEN', 'sequence_number' => 1, 'is_active' => true],
        ];

        foreach ($journals as $journal) {
            DB::table('journals')->updateOrInsert(
                ['code' => $journal['code']],
                array_merge($journal, ['created_at' => now(), 'updated_at' => now()])
            );
        }

        // Seed default payment terms
        $paymentTerms = [
            ['code' => 'IMM', 'name' => 'Immediate Payment', 'type' => 'immediate', 'days' => 0, 'is_default' => false, 'is_active' => true],
            ['code' => 'NET15', 'name' => 'Net 15 Days', 'type' => 'net_days', 'days' => 15, 'is_default' => false, 'is_active' => true],
            ['code' => 'NET30', 'name' => 'Net 30 Days', 'type' => 'net_days', 'days' => 30, 'is_default' => true, 'is_active' => true],
            ['code' => 'NET60', 'name' => 'Net 60 Days', 'type' => 'net_days', 'days' => 60, 'is_default' => false, 'is_active' => true],
            ['code' => 'NET90', 'name' => 'Net 90 Days', 'type' => 'net_days', 'days' => 90, 'is_default' => false, 'is_active' => true],
            ['code' => '2/10NET30', 'name' => '2/10 Net 30', 'type' => 'net_days', 'days' => 30, 'discount_percentage' => 2.00, 'discount_days' => 10, 'is_default' => false, 'is_active' => true],
            ['code' => 'EOM', 'name' => 'End of Month', 'type' => 'end_of_month', 'days' => 0, 'is_default' => false, 'is_active' => true],
        ];

        foreach ($paymentTerms as $term) {
            DB::table('payment_terms')->updateOrInsert(
                ['code' => $term['code']],
                array_merge($term, ['created_at' => now(), 'updated_at' => now()])
            );
        }

        // Seed some default exchange rates (USD as base)
        $exchangeRates = [
            ['from_currency' => 'USD', 'to_currency' => 'EUR', 'rate' => 0.92, 'effective_date' => now()->toDateString(), 'source' => 'manual'],
            ['from_currency' => 'USD', 'to_currency' => 'GBP', 'rate' => 0.79, 'effective_date' => now()->toDateString(), 'source' => 'manual'],
            ['from_currency' => 'USD', 'to_currency' => 'MYR', 'rate' => 4.47, 'effective_date' => now()->toDateString(), 'source' => 'manual'],
            ['from_currency' => 'USD', 'to_currency' => 'SGD', 'rate' => 1.34, 'effective_date' => now()->toDateString(), 'source' => 'manual'],
            ['from_currency' => 'USD', 'to_currency' => 'JPY', 'rate' => 149.50, 'effective_date' => now()->toDateString(), 'source' => 'manual'],
            ['from_currency' => 'USD', 'to_currency' => 'CNY', 'rate' => 7.24, 'effective_date' => now()->toDateString(), 'source' => 'manual'],
            ['from_currency' => 'USD', 'to_currency' => 'AUD', 'rate' => 1.52, 'effective_date' => now()->toDateString(), 'source' => 'manual'],
            ['from_currency' => 'USD', 'to_currency' => 'CAD', 'rate' => 1.36, 'effective_date' => now()->toDateString(), 'source' => 'manual'],
        ];

        foreach ($exchangeRates as $rate) {
            DB::table('exchange_rates')->updateOrInsert(
                [
                    'from_currency' => $rate['from_currency'],
                    'to_currency' => $rate['to_currency'],
                    'effective_date' => $rate['effective_date'],
                ],
                array_merge($rate, ['created_at' => now(), 'updated_at' => now()])
            );
        }

        $this->command->info('Phase 3 initial data seeded successfully!');
    }
}
