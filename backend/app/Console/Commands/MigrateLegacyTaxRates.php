<?php

namespace App\Console\Commands;

use App\Models\Tax;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MRD R10.2: migrate the legacy `tax_rates` table into the single rule store
 * (`taxes`), so the legacy Tax-rates admin page can be retired.
 *
 * Idempotent by code. Nothing is copied blindly: compound rates are SKIPPED
 * (compound ordering is engine Phase-5 scope — silently flattening them would
 * misstate tax), and each migrated row is flagged for accountant review of
 * scope/treatment. Existing codes are never overwritten.
 */
class MigrateLegacyTaxRates extends Command
{
    protected $signature = 'taxes:migrate-legacy-rates {--dry-run}';

    protected $description = 'Copy legacy tax_rates rules into the tax engine store (idempotent; skips compound)';

    public function handle(): int
    {
        $legacy = DB::table('tax_rates')->orderBy('id')->get();

        if ($legacy->isEmpty()) {
            $this->info('No legacy tax_rates rows to migrate.');

            return self::SUCCESS;
        }

        $scopeForType = [
            'purchase' => 'input',
            'sales' => 'output',
            'service' => 'output',
            'vat' => 'both',
            'gst' => 'both',
            'withholding' => 'input',
        ];

        $migrated = 0;
        $skipped = 0;
        $compoundSkipped = 0;

        foreach ($legacy as $rate) {
            if ((bool) ($rate->is_compound ?? false)) {
                $compoundSkipped++;
                $this->warn("SKIPPED (compound not supported by the engine yet): {$rate->code} {$rate->name} {$rate->rate}%");

                continue;
            }

            if (Tax::withTrashed()->where('code', $rate->code)->exists()) {
                $skipped++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line("would create: {$rate->code} ({$rate->name}) {$rate->rate}% scope=".($scopeForType[strtolower((string) $rate->type)] ?? 'both'));
                $migrated++;

                continue;
            }

            Tax::create([
                'name' => $rate->name,
                'code' => $rate->code,
                'type' => in_array(strtolower((string) $rate->type), ['gst', 'vat', 'sales', 'service', 'withholding'], true)
                    ? strtolower((string) $rate->type)
                    : 'other',
                'regime' => strtoupper((string) ($rate->type ?: 'OTHER')),
                'jurisdiction' => null,
                'scope' => $scopeForType[strtolower((string) $rate->type)] ?? 'both',
                'treatment' => (float) $rate->rate > 0 ? 'standard_rated' : 'zero_rated',
                'rate' => (float) $rate->rate,
                'is_inclusive' => false,
                'is_active' => (bool) ($rate->is_active ?? true),
            ]);

            $migrated++;
        }

        $this->info($this->option('dry-run')
            ? "Dry run: {$migrated} would migrate, {$skipped} existing codes skipped, {$compoundSkipped} compound skipped."
            : "Migrated {$migrated}, skipped {$skipped} existing, {$compoundSkipped} compound skipped. Review scope/jurisdiction of migrated rules in Accounting → Tax → Tax Engine.");

        return self::SUCCESS;
    }
}
