<?php

namespace App\Accounting\Services;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;
use App\Models\CompanySettings;

/**
 * Resolves GL accounts by mapping key instead of hardcoded codes.
 * Falls back to well-known codes if no mapping is configured.
 */
class AccountMappingService
{
    /** Cache resolved mappings per request */
    private array $cache = [];

    /**
     * Well-known fallback codes — DEPRECATED.
     * Kept only for seedDefaults() migration tool; resolve() no longer uses these.
     *
     * @deprecated Will be removed after all tenants have account_mappings configured.
     */
    private const FALLBACKS = [
        'cash' => '1010',
        'bank' => '1020',
        'accounts_receivable' => '1100',
        'inventory' => '1200',
        'accounts_payable' => '2100',
        'grni' => '2150',
        'sales_tax_payable' => '2200',
        // MRD D8: output and input tax must not share one control account.
        'input_tax' => '2210',
        'input_tax_blocked' => '5210',
        'wht_payable' => '2220',
        'sales_revenue' => '4100',
        'shipping_revenue' => '4101',
        'cogs' => '5100',
        'inventory_shrinkage' => '5200',
        'general_expense' => '5000',
        'depreciation_expense' => '6100',
        'accumulated_depreciation' => '1510',
        'gain_loss_disposal' => '7100',
        // 'inventory_adjustment_gain' (7300) deliberately removed: stock
        // increases no longer post a gain account (see
        // StockAdjustmentService::postValuationChange), so the account must
        // not be auto-seeded again.
        'sales_discount' => '4200',
        'unrealized_fx_gain_loss' => '7200',
        'realized_fx_gain_loss' => '7201',
        // MRD pricelist-multicurrency FR-7.2/FR-12.4: realized FX is booked
        // per direction. The gain leg reuses the historical 7201 account;
        // losses get their own 7202 (shared-pair default per MRD §17 Q4).
        'realized_fx_gain' => '7201',
        'realized_fx_loss' => '7202',
    ];

    /**
     * Resolve a ChartOfAccount by mapping key.
     *
     * @param  string  $key  e.g. 'accounts_receivable'
     * @param  string|null  $documentType  e.g. 'invoice', 'bill', 'pos_order'
     *
     * @throws \RuntimeException when no account can be resolved
     */
    public function resolve(string $key, ?string $documentType = null): ChartOfAccount
    {
        $cacheKey = "{$key}:{$documentType}";

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        // 1. Try document-type-specific mapping
        if ($documentType) {
            $mapping = AccountMapping::where('mapping_key', $key)
                ->where('document_type', $documentType)
                ->where('is_active', true)
                ->first();

            if ($mapping) {
                return $this->cache[$cacheKey] = $mapping->account;
            }
        }

        // The tenant-selected bank COA is managed in Accounting Settings >
        // Default Accounts. It takes precedence over the legacy seeded
        // `bank` mapping and its historical 1020 fallback.
        if ($key === 'bank') {
            $configuredBankId = CompanySettings::query()->value('default_bank_account_id');
            if ($configuredBankId) {
                $configuredBank = ChartOfAccount::query()->find($configuredBankId);
                if ($configuredBank) {
                    return $this->cache[$cacheKey] = $configuredBank;
                }
            }
        }

        // 2. Try default mapping (null document_type)
        $mapping = AccountMapping::where('mapping_key', $key)
            ->whereNull('document_type')
            ->where('is_active', true)
            ->first();

        if ($mapping) {
            return $this->cache[$cacheKey] = $mapping->account;
        }

        // Per-key fallback: if the tenant has chart_of_accounts but no
        // mapping for THIS key, auto-seed the well-known codes once. The
        // previous behaviour threw, which broke every test that hit the
        // posting engine and any tenant that skipped manual Default Accounts
        // configuration. Per-key (not whole-table) detection so a partially
        // seeded mapping table doesn't permanently block a missing key.
        if (ChartOfAccount::count() > 0 && ! AccountMapping::where('mapping_key', $key)->exists()) {
            $this->seedDefaults();
            // Reset per-request cache so the just-seeded rows are visible.
            $this->cache = [];

            if ($documentType) {
                $mapping = AccountMapping::where('mapping_key', $key)
                    ->where('document_type', $documentType)
                    ->where('is_active', true)
                    ->first();
                if ($mapping) {
                    return $this->cache[$cacheKey] = $mapping->account;
                }
            }

            $mapping = AccountMapping::where('mapping_key', $key)
                ->whereNull('document_type')
                ->where('is_active', true)
                ->first();
            if ($mapping) {
                return $this->cache[$cacheKey] = $mapping->account;
            }
        }

        throw new \RuntimeException(
            "Cannot resolve GL account for mapping key '{$key}'".
            ($documentType ? " (document type: {$documentType})" : '').
            '. Please configure default accounts in Company Settings > Default Accounts, '.
            'or ensure account_mappings are seeded for this tenant.'
        );
    }

    /**
     * Resolve account ID directly (shorthand).
     */
    public function resolveId(string $key, ?string $documentType = null): int
    {
        return $this->resolve($key, $documentType)->id;
    }

    /**
     * Map of FALLBACK code → COA type (asset/liability/equity/revenue/expense).
     * Used when seedDefaults needs to create a missing COA on the fly.
     */
    private const CODE_TYPES = [
        'cash' => 'asset',
        'bank' => 'asset',
        'accounts_receivable' => 'asset',
        'inventory' => 'asset',
        'accounts_payable' => 'liability',
        'grni' => 'liability',
        'sales_tax_payable' => 'liability',
        'input_tax' => 'asset',
        'input_tax_blocked' => 'expense',
        'wht_payable' => 'liability',
        'sales_revenue' => 'revenue',
        'shipping_revenue' => 'revenue',
        'cogs' => 'expense',
        'inventory_shrinkage' => 'expense',
        'general_expense' => 'expense',
        'depreciation_expense' => 'expense',
        'accumulated_depreciation' => 'asset',
        'gain_loss_disposal' => 'expense',
        'sales_discount' => 'revenue',
        'unrealized_fx_gain_loss' => 'expense',
        'realized_fx_gain_loss' => 'expense',
        'realized_fx_gain' => 'expense',
        'realized_fx_loss' => 'expense',
    ];

    /**
     * #775 (AU2): read-only health of the tenant's account-role mappings —
     * unmapped roles, roles mapped to a wrong-typed account or to an inactive
     * account. (Duplicate-control-account detection waits for the #763
     * decision on what makes two control accounts duplicates.)
     *
     * @return array{unmapped: array<int, array<string, string>>, wrong_type: array<int, array<string, mixed>>, inactive_account: array<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function health(): array
    {
        $expected = self::CODE_TYPES;

        $mappings = AccountMapping::query()
            ->whereNull('document_type')
            ->with('account')
            ->get()
            ->keyBy('mapping_key');

        $unmapped = [];
        $wrongType = [];
        $inactive = [];

        foreach ($expected as $key => $type) {
            $mapping = $mappings->get($key);

            if (! $mapping || ! $mapping->is_active || ! $mapping->account) {
                $unmapped[] = ['mapping_key' => $key, 'expected_type' => $type];

                continue;
            }

            if ($mapping->account->type !== $type) {
                $wrongType[] = [
                    'mapping_key' => $key,
                    'expected_type' => $type,
                    'account_id' => $mapping->account->id,
                    'account_code' => $mapping->account->code,
                    'account_type' => $mapping->account->type,
                ];
            }

            if (! $mapping->account->is_active) {
                $inactive[] = [
                    'mapping_key' => $key,
                    'account_id' => $mapping->account->id,
                    'account_code' => $mapping->account->code,
                ];
            }
        }

        return [
            'unmapped' => $unmapped,
            'wrong_type' => $wrongType,
            'inactive_account' => $inactive,
            'summary' => [
                'expected_roles' => count($expected),
                'unmapped' => count($unmapped),
                'wrong_type' => count($wrongType),
                'inactive_account' => count($inactive),
            ],
        ];
    }

    /**
     * #775: point a role at an account (the one-click "map" fix). Explicit
     * confirmation is the caller's contract; the write itself is a settings
     * change, not a posting.
     */
    public function map(string $key, int $accountId): AccountMapping
    {
        $account = ChartOfAccount::query()->findOrFail($accountId);

        return AccountMapping::updateOrCreate(
            ['mapping_key' => $key, 'document_type' => null],
            ['account_id' => $account->id, 'is_active' => true],
        );
    }

    /**
     * Seed default mappings for a new tenant based on existing COA codes.
     * If a well-known COA code is missing, creates it first (so a tenant
     * with a stripped-down COA can still post through the engine).
     */
    public function seedDefaults(): void
    {
        foreach (self::FALLBACKS as $key => $code) {
            $account = ChartOfAccount::where('code', $code)->first();

            if (! $account) {
                $type = self::CODE_TYPES[$key] ?? 'asset';
                $account = ChartOfAccount::create([
                    'code' => $code,
                    'name' => ucwords(str_replace('_', ' ', $key)),
                    'type' => $type,
                    'is_active' => true,
                ]);
            }

            if (! AccountMapping::where('mapping_key', $key)->whereNull('document_type')->exists()) {
                AccountMapping::create([
                    'mapping_key' => $key,
                    'account_id' => $account->id,
                    'document_type' => null,
                    'is_active' => true,
                ]);
            }
        }
    }
}
