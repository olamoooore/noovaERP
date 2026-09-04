<?php

namespace Database\Seeders;

use App\Models\AssuranceBlockingPolicy;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Issue noovaerp/NoovaERP#66 — AssuranceGate had nothing to enforce.
 *
 * AssuranceGate (ADR-005 §39.1) only blocks when an ACTIVE blocking
 * AssuranceBlockingPolicy row matches a candidate finding; with zero
 * policy rows the gate's blocking branch was dead on every tenant —
 * enforcement code wired but no rule to enforce.
 *
 * `assurance_blocking_policies` is a CENTRAL (public-schema) table keyed
 * by tenant_id (its migration lives in database/migrations/, and the
 * gate filters `tenant_id = ? OR tenant_id IS NULL`), so this seeder
 * provisions a baseline policy pair for every tenant from the central
 * connection — run it with `php artisan db:seed
 * --class=Database\\Seeders\\AssuranceBlockingPolicySeeder` (NOT
 * tenants:seed).
 *
 * Baseline (conservative by design — see ACC-010):
 *  - BLOCK at CRITICAL: unresolved CRITICAL assurance findings block the
 *    material posting actions (invoice/bill/payment postings, close
 *    cycle, manual journal post) for the records they are attached to.
 *  - WARN at HIGH: non-blocking visibility row (block_mode != 'BLOCK'
 *    never blocks; AssuranceGate::scopeBlocking only reads BLOCK rows).
 *
 * Severity matching in AssuranceBlockingPolicy::shouldBlock is
 * >= threshold (LOW..CRITICAL), category matching is exact-or-'*'.
 */
class AssuranceBlockingPolicySeeder extends Seeder
{
    public const POLICY_VERSION = '1.0';

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function baselineRows(): array
    {
        return [
            [
                'policy_version' => self::POLICY_VERSION,
                'policy_name' => 'ACC-010 baseline — block CRITICAL findings (all actions)',
                'finding_category' => '*',
                'assertion_level' => null,
                'severity_threshold' => 'CRITICAL',
                'risk_threshold' => null,
                'action_scope' => null,
                'block_mode' => 'BLOCK',
                'effective_from' => null,
                'effective_to' => null,
                'status' => 'active',
            ],
            [
                'policy_version' => self::POLICY_VERSION,
                'policy_name' => 'ACC-010 baseline — warn HIGH findings (non-blocking)',
                'finding_category' => '*',
                'assertion_level' => null,
                'severity_threshold' => 'HIGH',
                'risk_threshold' => null,
                'action_scope' => null,
                'block_mode' => 'WARN',
                'effective_from' => null,
                'effective_to' => null,
                'status' => 'active',
            ],
        ];
    }

    public function run(): void
    {
        if (function_exists('tenant') && tenant()) {
            // Running inside a tenant-provisioning seed (DatabaseSeeder is
            // the configured SeedDatabase class): write THIS tenant's
            // baseline rows into the central table explicitly.
            $this->seedTenant((string) tenant()->id);

            return;
        }

        // Central run (php artisan db:seed --class=...): provision every
        // tenant that does not yet have the baseline.
        foreach (Tenant::all() as $tenant) {
            $this->seedTenant((string) $tenant->id);
        }
    }

    private function seedTenant(string $tenantId): void
    {
        // Qualified `public.` target: this seeder may run inside a tenant
        // context (provisioning pipeline) where the unqualified table name
        // would resolve via the tenant search_path. The policy table is
        // central (issue #61 batch); be explicit.
        foreach (self::baselineRows() as $row) {
            DB::table('public.assurance_blocking_policies')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                    'policy_name' => $row['policy_name'],
                ],
                $row + [
                    'tenant_id' => $tenantId,
                    'updated_at' => now(),
                ]
            );
        }
    }
}
