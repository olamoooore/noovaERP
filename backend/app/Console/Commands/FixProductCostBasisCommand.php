<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fix missing product cost basis for stock-tracked products.
 *
 * Sets a default cost for products where cost is NULL or 0,
 * using a percentage of the selling price (default 55%).
 * Products with zero price get a flat minimum cost.
 */
class FixProductCostBasisCommand extends Command
{
    protected $signature = 'products:fix-cost-basis
                            {--tenant= : Tenant UUID to fix (default: all tenants)}
                            {--ratio=0.55 : Cost as ratio of selling price (0.0-1.0)}
                            {--min-cost=1.00 : Minimum cost for products with zero price}
                            {--dry-run : Preview changes without applying}';

    protected $description = 'Fix zero cost basis for stock-tracked products';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $ratio = (float) $this->option('ratio');
        $minCost = (float) $this->option('min-cost');
        $dryRun = $this->option('dry-run');

        if ($ratio <= 0 || $ratio > 1) {
            $this->error('Ratio must be between 0.0 and 1.0');
            return 1;
        }

        $tenants = $this->resolveTenants($tenantId);

        if ($tenants->isEmpty()) {
            $this->error('No tenants found.');
            return 1;
        }

        $grandTotalFixed = 0;
        $grandTotalSkipped = 0;

        foreach ($tenants as $tenant) {
            $schema = $this->schemaName($tenant->id);

            if (! $this->schemaExists($schema)) {
                $this->warn("Schema {$schema} does not exist. Skipping.");
                continue;
            }

            $stats = $this->fixTenant($schema, $ratio, $minCost, $dryRun);

            $this->info(sprintf(
                "Tenant: %s (%s) | Fixed: %d | Skipped (zero price): %d",
                $tenant->name,
                substr($tenant->id, 0, 8),
                $stats['fixed'],
                $stats['skipped']
            ));

            $grandTotalFixed += $stats['fixed'];
            $grandTotalSkipped += $stats['skipped'];
        }

        $mode = $dryRun ? 'PREVIEW (dry-run)' : 'APPLIED';
        $this->newLine();
        $this->info("{$mode}: Total fixed: {$grandTotalFixed}, Skipped (zero price): {$grandTotalSkipped}");

        return 0;
    }

    private function resolveTenants(?string $tenantId)
    {
        DB::statement('SET search_path TO public');

        $query = DB::table('tenants');

        if ($tenantId) {
            $query->where('id', $tenantId);
        }

        return $query->select('id', 'name')->get();
    }

    private function schemaName(string $tenantId): string
    {
        return $tenantId;
    }

    private function schemaExists(string $schema): bool
    {
        $result = DB::select(
            "SELECT EXISTS (SELECT 1 FROM information_schema.schemata WHERE schema_name = ?) as e",
            [$schema]
        );

        return (bool) ($result[0]->e ?? false);
    }

    private function fixTenant(string $schema, float $ratio, float $minCost, bool $dryRun): array
    {
        // Count products before fix
        $before = DB::select(
            "SELECT 
                COUNT(*) FILTER (WHERE cost IS NULL OR cost = 0) as zero_cost,
                COUNT(*) FILTER (WHERE cost IS NULL OR cost = 0 AND price > 0) as fixable,
                COUNT(*) FILTER (WHERE cost IS NULL OR cost = 0 AND price = 0) as unfixable
            FROM \"{$schema}\".products
            WHERE track_inventory = true"
        )[0];

        if ($dryRun) {
            return [
                'fixed' => (int) $before->fixable,
                'skipped' => (int) $before->unfixable,
            ];
        }

        // Fix products with price > 0: cost = price * ratio
        $fixed = DB::update(
            "UPDATE \"{$schema}\".products
             SET cost = ROUND(price * ?, 2),
                 updated_at = NOW()
             WHERE track_inventory = true
               AND (cost IS NULL OR cost = 0)
               AND price > 0",
            [$ratio]
        );

        // Fix products with price = 0: cost = minCost
        $skipped = DB::update(
            "UPDATE \"{$schema}\".products
             SET cost = ?,
                 updated_at = NOW()
             WHERE track_inventory = true
               AND (cost IS NULL OR cost = 0)
               AND price = 0",
            [$minCost]
        );

        return [
            'fixed' => $fixed,
            'skipped' => $skipped,
        ];
    }
}
