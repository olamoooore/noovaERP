<?php

namespace Database\Seeders;

use App\Models\AccountingControlAccountPolicy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds AccountingControlAccountPolicy rows for the canonical control accounts
 * (AR Control, AP Control, Bank Control, Inventory Control, Fixed Asset Control)
 * identified by ChartOfAccount.code or .name. ADR-005 §17 — each control
 * account must have an explicit source-allow policy.
 *
 * Runs against the tenant's search_path (i.e. tenant schema). Idempotent:
 * re-running the seeder will not create duplicate policies for the same account.
 */
class AccountingControlAccountPolicySeeder extends Seeder
{
    /**
     * Map of account-code-prefix → control account type and the source capabilities
     * allowed to post to that control account. Conservative defaults chosen
     * to match the canonical accounting domain (ADR-005 §17):
     *
     *   - AR_CONTROL: only AR/AP sub-system sources (no manual journals, no FX,
     *     no payment posting without an exception).
     *   - AP_CONTROL: only AP-side sources (no AR, no manual journals without
     *     exceptional authority).
     *   - BANK_CONTROL: only payment + reconciliation sources.
     *   - INVENTORY_CONTROL: only inventory source capability.
     *   - FIXED_ASSET_CONTROL: only fixed-asset capability.
     */
    private const CONTROL_ACCOUNT_RULES = [
        'AR' => [
            'type' => 'AR_CONTROL',
            'allowed_sources' => ['AR', 'ACC-003', 'reconciliation_adjustment'],
            'exceptional_required' => true,
        ],
        'AP' => [
            'type' => 'AP_CONTROL',
            'allowed_sources' => ['AP', 'ACC-004', 'OCR', 'reconciliation_adjustment'],
            'exceptional_required' => true,
        ],
        'BANK' => [
            'type' => 'BANK_CONTROL',
            'allowed_sources' => ['BANK', 'ACC-006', 'PAYMENT', 'reconciliation_adjustment'],
            'exceptional_required' => true,
        ],
        'INV' => [
            'type' => 'INVENTORY_CONTROL',
            'allowed_sources' => ['INVENTORY', 'ACC-007'],
            'exceptional_required' => false,
        ],
        'FA' => [
            'type' => 'FIXED_ASSET_CONTROL',
            'allowed_sources' => ['FIXED_ASSET', 'ACC-007'],
            'exceptional_required' => false,
        ],
        'INVENTORY' => [
            'type' => 'INVENTORY_CONTROL',
            'allowed_sources' => ['INVENTORY', 'ACC-007'],
            'exceptional_required' => false,
        ],
    ];

    public function run(): void
    {
        $tenantId = tenant('id');
        if (! $tenantId) {
            // No tenant context — skip (this seeder is tenant-scoped only)
            return;
        }

        $accounts = DB::table('chart_of_accounts')
            ->where(function ($q) {
                foreach (array_keys(self::CONTROL_ACCOUNT_RULES) as $prefix) {
                    $q->orWhere('code', 'like', $prefix.'%')
                        ->orWhere('name', 'ilike', '%control%');
                }
            })
            ->get();

        $created = 0;
        foreach ($accounts as $account) {
            $type = $this->classifyAccount($account);
            if ($type === null) {
                continue;
            }

            $rule = self::CONTROL_ACCOUNT_RULES[$type];

            // Skip if a policy already exists for this account
            $existing = AccountingControlAccountPolicy::where('tenant_id', $tenantId)
                ->where('account_id', $account->id)
                ->where('status', 'active')
                ->first();
            if ($existing) {
                continue;
            }

            AccountingControlAccountPolicy::create([
                'tenant_id' => $tenantId,
                'account_id' => $account->id,
                'control_account_type' => $rule['type'],
                'allowed_source_capabilities' => $rule['allowed_sources'],
                'requires_exceptional_authority' => $rule['exceptional_required'],
                'exceptional_permission_code' => $rule['exceptional_required']
                    ? 'accounting.control_account_exception'
                    : null,
                'requires_workflow_approval' => $rule['exceptional_required'],
                'reconciliation_consequence_required' => true,
                'blocking_policy' => 'BLOCK',
                'effective_from' => now()->toDateString(),
                'status' => 'active',
            ]);

            $created++;
        }

        if ($created > 0) {
            $this->command?->info("Seeded {$created} AccountingControlAccountPolicy row(s) for tenant {$tenantId}.");
        }
    }

    private function classifyAccount(object $account): ?string
    {
        $code = strtoupper((string) $account->code);
        $name = strtoupper((string) $account->name);

        foreach (array_keys(self::CONTROL_ACCOUNT_RULES) as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return $prefix;
            }
        }

        // Match by name containing "control"
        if (str_contains($name, 'CONTROL')) {
            if (str_contains($name, 'AR') || str_contains($name, 'RECEIVABLE')) {
                return 'AR';
            }
            if (str_contains($name, 'AP') || str_contains($name, 'PAYABLE')) {
                return 'AP';
            }
            if (str_contains($name, 'BANK') || str_contains($name, 'CASH')) {
                return 'BANK';
            }
            if (str_contains($name, 'INVENTORY') || str_contains($name, 'STOCK')) {
                return 'INVENTORY';
            }
            if (str_contains($name, 'FIXED') || str_contains($name, 'ASSET')) {
                return 'FA';
            }
        }

        return null;
    }
}
