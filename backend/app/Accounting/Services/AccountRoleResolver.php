<?php

namespace App\Accounting\Services;

use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AccountRoleResolver — Phase 2 account resolution service.
 *
 * Resolution chain:
 *   1. entity_account_roles (role-based assignment)
 *   2. entity_account_mappings → master_coa_nodes (structured mapping)
 *   3. AccountMappingService (transitional bridge — legacy fallback)
 *
 * Controlled by env: ACCOUNT_RESOLUTION=roles|legacy (default: legacy)
 */
class AccountRoleResolver
{
    /** Maps posting mapping keys to account_roles.code */
    private const KEY_TO_ROLE = [
        'cash' => 'CASH_MAIN',
        'bank' => 'BANK_MAIN',
        'card_clearing' => 'CARD_CLEARING',
        'cheque_clearing' => 'CHEQUE_CLEARING',
        'gateway_receivable' => 'GATEWAY_RECEIVABLE',
        'accounts_receivable' => 'AR_CONTROL',
        'accounts_payable' => 'AP_CONTROL',
        'inventory' => 'INVENTORY',
        'cogs' => 'COGS',
        'sales_tax_payable' => 'TAX_OUTPUT',
        'input_tax' => 'TAX_INPUT',
        'unrealized_fx_gain_loss' => 'FX_GAIN',
        'realized_fx_gain_loss' => 'FX_LOSS',
        // MRD pricelist-multicurrency FR-7.2: directional realized FX keys
        // resolve to the FX gain/loss role pair (falls through to the legacy
        // mapper when the tenant has not assigned the roles).
        'realized_fx_gain' => 'FX_GAIN',
        'realized_fx_loss' => 'FX_LOSS',
        'retained_earnings' => 'RETAINED_EARNINGS',
        'general_expense' => 'SUSPENSE',
        'sales_revenue' => null, // no direct role — use AccountMappingService
        'shipping_revenue' => null, // no direct role — use AccountMappingService
        'depreciation_expense' => null,
        'accumulated_depreciation' => null,
        'gain_loss_disposal' => null,
        'sales_discount' => null,
    ];

    private array $cache = [];

    public function __construct(
        private readonly AccountMappingService $legacyMapper,
    ) {}

    /**
     * Is role-based resolution active?
     */
    public function isRoleBased(): bool
    {
        return config('accounting.account_resolution', env('ACCOUNT_RESOLUTION', 'roles')) === 'roles';
    }

    /**
     * Resolve a ChartOfAccount by mapping key.
     */
    public function resolve(string $key, ?string $documentType = null, ?int $legalEntityId = null): ChartOfAccount
    {
        if (! $this->isRoleBased()) {
            return $this->legacyMapper->resolve($key, $documentType);
        }

        $cacheKey = "{$key}:{$documentType}:{$legalEntityId}";
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // 1. Try entity_account_roles via role code mapping
        $roleCode = self::KEY_TO_ROLE[$key] ?? null;

        if ($roleCode === null) {
            // This key has no direct role mapping — falls through to legacy mapper.
            // NOTE: 'general_expense' maps to SUSPENSE which is intentionally wrong for
            // expense lines — callers must supply an explicit accountId for expense postings.
            Log::debug('AccountRoleResolver: no role mapping for key, using legacy mapper', [
                'key' => $key,
                'document_type' => $documentType,
                'legal_entity' => $legalEntityId,
            ]);
        } elseif ($roleCode === 'SUSPENSE') {
            Log::warning('AccountRoleResolver: key resolves to SUSPENSE account — verify this is intentional', [
                'key' => $key,
                'document_type' => $documentType,
            ]);
        }

        if ($roleCode) {
            $account = $this->resolveByRole($roleCode, $legalEntityId);
            if ($account) {
                return $this->cache[$cacheKey] = $account;
            }
        }

        // 2. Fall back to legacy AccountMappingService (transitional bridge)
        return $this->cache[$cacheKey] = $this->legacyMapper->resolve($key, $documentType);
    }

    /**
     * Shorthand: resolve to account ID.
     */
    public function resolveId(string $key, ?string $documentType = null, ?int $legalEntityId = null): int
    {
        return $this->resolve($key, $documentType, $legalEntityId)->id;
    }

    /**
     * Resolve by role code from entity_account_roles table, scoped to a legal entity.
     */
    private function resolveByRole(string $roleCode, ?int $legalEntityId = null): ?ChartOfAccount
    {
        $query = DB::table('entity_account_roles as ear')
            ->join('account_roles as ar', 'ar.id', '=', 'ear.account_role_id')
            ->where('ar.code', $roleCode)
            ->where('ear.is_active', true);

        if ($legalEntityId) {
            $query->where('ear.legal_entity_id', $legalEntityId);
        }

        $accountId = $query->value('ear.chart_of_account_id');

        if (! $accountId) {
            return null;
        }

        return ChartOfAccount::where('id', $accountId)->where('is_active', true)->first();
    }

    /**
     * #885 (UX3): assign an account to an entity's role by role code — the
     * shared write path behind the Default Accounts screen and the Account
     * Roles API. Deactivates the entity's current assignment for the role
     * first, so one entity has at most one active account per role.
     */
    public function assignByRoleCode(string $roleCode, int $accountId, int $legalEntityId, ?int $assignedBy = null): void
    {
        $roleId = DB::table('account_roles')->where('code', $roleCode)->value('id');

        if (! $roleId) {
            throw new \RuntimeException("Account role '{$roleCode}' is not defined for this tenant.");
        }

        $this->assignByRoleId((int) $roleId, $accountId, $legalEntityId, $assignedBy);
    }

    /**
     * #885 (UX3): clear an entity's assignment for a role so resolution falls
     * back to the tenant-wide mapping.
     */
    public function unassignByRoleCode(string $roleCode, int $legalEntityId): void
    {
        DB::table('entity_account_roles')
            ->where('legal_entity_id', $legalEntityId)
            ->whereIn('account_role_id', function ($query) use ($roleCode) {
                $query->select('id')->from('account_roles')->where('code', $roleCode);
            })
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    /**
     * #885 (UX3): assign by account_role_id (used by AccountRolesController).
     */
    public function assignByRoleId(int $roleId, int $accountId, int $legalEntityId, ?int $assignedBy = null): void
    {
        DB::transaction(function () use ($roleId, $accountId, $legalEntityId, $assignedBy) {
            DB::table('entity_account_roles')
                ->where('account_role_id', $roleId)
                ->where('legal_entity_id', $legalEntityId)
                ->update(['is_active' => false, 'updated_at' => now()]);

            DB::table('entity_account_roles')->updateOrInsert(
                [
                    'legal_entity_id' => $legalEntityId,
                    'account_role_id' => $roleId,
                    'chart_of_account_id' => $accountId,
                ],
                [
                    'is_active' => true,
                    'assigned_by' => $assignedBy,
                    'assigned_at' => now(),
                    'updated_at' => now(),
                ],
            );
        });
    }

    /**
     * Get all unassigned mandatory roles for a specific legal entity.
     */
    public function getUnassignedMandatoryRoles(?int $legalEntityId = null): array
    {
        $mandatoryRoles = DB::table('account_roles')
            ->where('is_mandatory', true)
            ->pluck('code', 'id');

        $query = DB::table('entity_account_roles')
            ->where('is_active', true);

        if ($legalEntityId) {
            $query->where('legal_entity_id', $legalEntityId);
        }

        $assignedRoleIds = $query->pluck('account_role_id')->unique();

        $unassigned = [];
        foreach ($mandatoryRoles as $id => $code) {
            if (! $assignedRoleIds->contains($id)) {
                $unassigned[] = $code;
            }
        }

        return $unassigned;
    }
}
