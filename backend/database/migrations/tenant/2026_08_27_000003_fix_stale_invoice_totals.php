<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('invoice_lines', 'tax_inclusive')) {
            return;
        }

        $invoices = DB::table('invoices')->select('id', 'subtotal', 'tax_amount', 'total')->get();

        $fixed = 0;
        foreach ($invoices as $inv) {
            $lines = DB::table('invoice_lines')
                ->where('invoice_id', $inv->id)
                ->get();

            if ($lines->isEmpty()) {
                continue;
            }

            $correctSub = 0;
            foreach ($lines as $line) {
                $qty = (float) $line->quantity;
                $price = (float) $line->unit_price;
                $taxRate = (float) $line->tax_rate;
                $discount = (float) ($line->discount_amount ?? 0);
                $taxInclusive = (bool) $line->tax_inclusive;

                $gross = $qty * $price;
                if ($taxInclusive) {
                    $divisor = 1 + ($taxRate / 100);
                    $net = $divisor > 0 ? $gross / $divisor : $gross;
                    $discountRatio = $gross > 0 ? $discount / $gross : 0;
                    $correctSub += $net * (1 - $discountRatio);
                } else {
                    $correctSub += $gross - $discount;
                }
            }

            $correctTax = $lines->sum(fn($l) => (float) $l->tax_amount);
            $correctTotal = $correctSub + $correctTax;

            $subDiff = abs((float) $inv->subtotal - $correctSub);
            $taxDiff = abs((float) $inv->tax_amount - $correctTax);
            $totalDiff = abs((float) $inv->total - $correctTotal);

            if ($subDiff > 0.01 || $taxDiff > 0.01 || $totalDiff > 0.01) {
                DB::table('invoices')
                    ->where('id', $inv->id)
                    ->update([
                        'subtotal' => round($correctSub, 2),
                        'tax_amount' => round($correctTax, 2),
                        'total' => round($correctTotal, 2),
                    ]);
                $fixed++;
            }
        }

        if ($fixed > 0) {
            \Log::info("InvoiceTotalsFixMigration: Fixed {$fixed} invoices with stale subtotal/tax/total values");
        }
    }

    public function down(): void
    {
        // Irreversible — data fix only
    }
};
