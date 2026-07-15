<?php

namespace App\Console\Commands;

use App\Models\Quotation;
use Illuminate\Console\Command;

class RecalculateQuotationTotals extends Command
{
    protected $signature = 'quotations:recalculate-totals';

    protected $description = 'Recompute line-level and header totals for all quotations (repairs historical data).';

    public function handle(): int
    {
        $count = 0;
        Quotation::with('lines')
            ->orderBy('id')
            ->chunkById(200, function ($quotations) use (&$count) {
                foreach ($quotations as $quotation) {
                    $quotation->calculateTotals();
                    $count++;
                }
            });

        $this->info("Recalculated totals for {$count} quotation(s).");

        return self::SUCCESS;
    }
}
