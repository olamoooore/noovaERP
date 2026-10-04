<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * #762 (A2) — tenants that already ran the MY country seeder have equity
 * accounts typed as current liabilities (and Dividend/PCB typed as expenses).
 * Reclassify by account code; only the type/subtype changes — every posting
 * stays exactly where it was. Each change is audit-logged.
 */
return new class extends Migration
{
    /** @var array<string, array{type: string, subtype: string}> */
    private const RECLASSIFY = [
        '221500' => ['type' => 'equity', 'subtype' => 'equity'],
        '310000' => ['type' => 'equity', 'subtype' => 'equity'],
        '320000' => ['type' => 'equity', 'subtype' => 'equity'],
        '330000' => ['type' => 'equity', 'subtype' => 'equity'],
        '522900' => ['type' => 'equity', 'subtype' => 'equity'],
        '521820' => ['type' => 'liability', 'subtype' => 'liability_current'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('chart_of_accounts')) {
            return;
        }

        foreach (self::RECLASSIFY as $code => $target) {
            // PHP casts numeric-string array keys to int, and drivers that bind
            // integers as int4 compare against the varchar `code` column as
            // `character varying = integer` (seen on legacy tenant schemas).
            // Compare as text so every driver and column shape matches.
            $rows = DB::table('chart_of_accounts')
                ->whereRaw('code::text = ?', [(string) $code])
                ->where('type', '!=', $target['type'])
                ->get(['id', 'code', 'name', 'type']);

            foreach ($rows as $row) {
                DB::table('chart_of_accounts')->where('id', $row->id)->update([
                    'type' => $target['type'],
                    'subtype' => $target['subtype'],
                    'updated_at' => now(),
                ]);

                Log::info('MY CoA account reclassified (#762): postings untouched', [
                    'account_id' => $row->id,
                    'code' => $row->code,
                    'name' => $row->name,
                    'from_type' => $row->type,
                    'to_type' => $target['type'],
                    'to_subtype' => $target['subtype'],
                ]);
            }
        }
    }

    public function down(): void
    {
        // A classification correction is not reverted silently; downstream
        // reports rely on the corrected types.
    }
};
