<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * #665 EI3: bulk-map existing products to their LHDN classification and
 * UN/ECE unit codes. Per-product codes are never overwritten unless
 * --overwrite is passed, so a hand-set code survives a category sweep.
 */
class BackfillProductEinvoiceCodesCommand extends Command
{
    protected $signature = 'products:backfill-einvoice-codes
        {--category= : Limit to products in this product_category id}
        {--classification= : LHDN classification code to set}
        {--unit= : UN/ECE unit code to set}
        {--overwrite : Also overwrite products that already have a code}';

    protected $description = 'Bulk-set product e-invoice classification / unit codes (#665).';

    public function handle(): int
    {
        $classification = $this->option('classification');
        $unit = $this->option('unit');

        if ($classification === null && $unit === null) {
            $this->error('Provide --classification and/or --unit.');

            return Command::FAILURE;
        }

        $total = 0;

        foreach (Tenant::all() as $tenant) {
            tenancy()->initialize($tenant);

            try {
                $base = Product::query();
                if ($category = $this->option('category')) {
                    $base->where('category_id', (int) $category);
                }

                if ($classification !== null) {
                    $query = (clone $base)->when(
                        ! $this->option('overwrite'),
                        fn ($q) => $q->whereNull('einvoice_classification_code'),
                    );
                    $total += $query->update(['einvoice_classification_code' => $classification]);
                }

                if ($unit !== null) {
                    $query = (clone $base)->when(
                        ! $this->option('overwrite'),
                        fn ($q) => $q->whereNull('einvoice_unit_code'),
                    );
                    $total += $query->update(['einvoice_unit_code' => $unit]);
                }
            } catch (\Throwable $e) {
                Log::warning('E-invoice product code backfill failed for tenant', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("Tenant {$tenant->id}: {$e->getMessage()}");
            } finally {
                tenancy()->end();
            }
        }

        $this->info("Backfill complete: {$total} product field(s) updated.");

        return Command::SUCCESS;
    }
}
