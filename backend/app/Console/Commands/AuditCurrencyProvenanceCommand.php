<?php

namespace App\Console\Commands;

use App\Models\CompanySettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Issue #214: the #98 currency backfill stamped historical documents with the
 * tenant's CURRENT base_currency, because no historical settings audit exists
 * to reconstruct the currency in effect when each document was created. For a
 * tenant that changed base_currency, those rows are now silently mislabelled.
 *
 * This command cannot recover the original value, but it surfaces the rows
 * whose provenance is unverifiable — documents denominated in the current base
 * currency that were created BEFORE the tenant's settings were last changed —
 * so they can be reviewed and corrected manually. Read-only.
 */
class AuditCurrencyProvenanceCommand extends Command
{
    protected $signature = 'finance:audit-currency-provenance
                            {--json : Output machine-readable JSON}
                            {--limit=20 : Sample rows to display per table}';

    protected $description = 'Flag documents whose currency provenance cannot be verified (created before the last company-settings change)';

    public function handle(): int
    {
        $settings = CompanySettings::query()->orderBy('id')->first();

        if (! $settings || ! $settings->base_currency || ! $settings->updated_at) {
            $this->warn('No company settings with a base currency and update timestamp — nothing to audit.');

            return self::SUCCESS;
        }

        $baseCurrency = strtoupper((string) $settings->base_currency);
        $changedAt = $settings->updated_at;

        $tables = [
            'invoices' => 'invoice_number',
            'bills' => 'bill_number',
            'payments' => 'payment_number',
            'bill_payments' => 'payment_number',
        ];

        $report = [];

        foreach ($tables as $table => $numberColumn) {
            if (! DB::getSchemaBuilder()->hasTable($table) || ! DB::getSchemaBuilder()->hasColumn($table, 'currency_code')) {
                continue;
            }

            $query = DB::table($table)
                ->where('currency_code', $baseCurrency)
                ->where('created_at', '<', $changedAt);

            $count = (clone $query)->count();

            if ($count === 0) {
                continue;
            }

            $samples = (clone $query)
                ->orderBy('created_at')
                ->limit((int) $this->option('limit'))
                ->get(['id', $numberColumn.' as document_number', 'created_at'])
                ->map(fn ($row) => [
                    'id' => $row->id,
                    'document_number' => $row->document_number,
                    'created_at' => (string) $row->created_at,
                ])
                ->all();

            $report[$table] = ['count' => $count, 'samples' => $samples];
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'base_currency' => $baseCurrency,
                'settings_changed_at' => (string) $changedAt,
                'candidates' => $report,
            ], JSON_PRETTY_PRINT));
        } elseif ($report === []) {
            $this->info("Currency provenance clean: no {$baseCurrency} documents predate the last settings change.");
        } else {
            $this->warn("Documents denominated {$baseCurrency} created before the last settings change ({$changedAt}) — provenance unverifiable, review manually:");
            foreach ($report as $table => $data) {
                $this->line("  {$table}: {$data['count']} row(s)");
                foreach ($data['samples'] as $sample) {
                    $this->line("    #{$sample['id']} {$sample['document_number']} (created {$sample['created_at']})");
                }
            }
        }

        return self::SUCCESS;
    }
}
