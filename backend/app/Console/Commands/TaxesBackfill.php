<?php

namespace App\Console\Commands;

use App\Models\PostingAuditLog;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tenancy;

/**
 * #267: sanctioned historical tax-data backfill tooling.
 *
 * Dry-run by default: per-tenant report of
 *   - Scope A: identity-only re-tag candidates (journal_entry_lines missing
 *     tax_code with a matching tax_transaction_details row on the same entry);
 *   - Scope B: rate/base/tax anomalies (percentage/basis confusion, legacy
 *     decimal rates) — REPORT ONLY, never auto-executed.
 *
 * --execute requires the confirmation token and applies ONLY identity
 * re-tagging, transactionally, appending posting_audit_log rows. It never
 * deletes and never rewrites trail amounts (append corrections only per the
 * finance decision on this issue).
 */
class TaxesBackfill extends Command
{
    public const TOKEN = 'APPLY-IDENTITY-BACKFILL';

    protected $signature = 'taxes:backfill
        {--tenant= : Limit to one tenant id (default: all)}
        {--execute : Apply identity-only re-tagging}
        {--token= : Confirmation token required with --execute}';

    protected $description = 'Historical tax backfill (dry-run report; --execute re-tags identities only)';

    public function handle(Tenancy $tenancy): int
    {
        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q, $id) => $q->where('id', $id))
            ->orderBy('id')
            ->get();

        if ($tenants->isEmpty()) {
            $this->info('No tenants found.');

            return self::SUCCESS;
        }

        $execute = (bool) $this->option('execute');

        if ($execute && ! $this->tokenIsValid($this->option('token'))) {
            $this->error('Refusing to execute: pass --token='.self::TOKEN.' (identity-only scope).');

            return self::FAILURE;
        }

        $originalTenant = $tenancy->tenant;
        $originalPath = (string) (DB::selectOne('SHOW search_path')->search_path ?? 'public');

        try {
            foreach ($tenants as $tenant) {
                $tenancy->initialize($tenant);

                try {
                    $report = $this->auditCurrentSchema($execute);

                    $this->info("{$tenant->getTenantKey()}: identity candidates=".$report['identity_candidates']
                        .' re-tagged='.$report['retagged']
                        .' anomalies='.$report['anomalies']
                        .($execute ? '' : ' (dry-run — no writes)'));
                } finally {
                    $tenancy->end();
                }
            }
        } finally {
            DB::statement('SET search_path TO '.DB::connection()->getPdo()->quote($originalPath));

            if ($originalTenant) {
                $tenancy->initialize($originalTenant);
            }
        }

        return self::SUCCESS;
    }

    public function tokenIsValid(?string $token): bool
    {
        return $token === self::TOKEN;
    }

    /**
     * Audit (and optionally re-tag) the CURRENT tenant schema.
     *
     * @return array{identity_candidates: int, retagged: int, anomalies: int}
     */
    public function auditCurrentSchema(bool $execute): array
    {
        $lines = DB::table('journal_entry_lines as jel')
            ->whereNull('jel.tax_code')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('tax_transaction_details as ttd')
                    ->whereColumn('ttd.journal_entry_id', 'jel.journal_entry_id');
            })
            ->get(['jel.id', 'jel.journal_entry_id', 'jel.debit', 'jel.credit']);

        $candidates = [];

        foreach ($lines as $line) {
            $amount = round(max((float) $line->debit, (float) $line->credit), 2);

            $trail = DB::table('tax_transaction_details')
                ->where('journal_entry_id', $line->journal_entry_id)
                ->whereRaw('round(tax_amount::numeric, 2) = ?', [$amount])
                ->first();

            if ($trail && $trail->tax_code !== null) {
                $candidates[] = [
                    'line_id' => (int) $line->id,
                    'journal_entry_id' => (int) $line->journal_entry_id,
                    'tax_code' => (string) $trail->tax_code,
                    'tax_rule_id' => $trail->tax_rule_id !== null ? (int) $trail->tax_rule_id : null,
                    'amount' => $amount,
                ];
            }
        }

        $retagged = 0;

        if ($execute) {
            foreach ($candidates as $candidate) {
                DB::transaction(function () use ($candidate, &$retagged) {
                    DB::table('journal_entry_lines')
                        ->where('id', $candidate['line_id'])
                        ->update([
                            'tax_code' => $candidate['tax_code'],
                            'tax_id' => $candidate['tax_rule_id'],
                            'updated_at' => now(),
                        ]);

                    PostingAuditLog::create([
                        'document_type' => 'backfill',
                        'document_id' => $candidate['journal_entry_id'],
                        'journal_entry_id' => $candidate['journal_entry_id'],
                        'action' => 'tax_backfill_identity',
                        'actor_user_id' => null,
                        'reason_code' => 'BACKFILL',
                        'payload_snapshot' => [
                            'line_id' => $candidate['line_id'],
                            'tax_code' => $candidate['tax_code'],
                            'tax_rule_id' => $candidate['tax_rule_id'],
                            'amount' => $candidate['amount'],
                            'scope' => 'identity_only',
                        ],
                    ]);

                    $retagged++;
                });
            }
        }

        // Scope B: report-only anomaly scan (never auto-executed).
        $anomalies = DB::table('tax_transaction_details')
            ->whereNotNull('tax_rate')
            ->where('tax_rate', '>', 0)
            ->where('tax_amount', '>', 0)
            ->whereRaw('abs(round(taxable_amount * tax_rate / 100.0, 2) - tax_amount) > 0.02')
            ->count();

        return [
            'identity_candidates' => count($candidates),
            'retagged' => $retagged,
            'anomalies' => $anomalies,
        ];
    }
}