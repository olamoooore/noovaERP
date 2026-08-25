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
