<?php

namespace App\Accounting\Services;

use App\Models\AccountMapping;
use App\Models\ChartOfAccount;

/**
 * Resolves GL accounts by mapping key instead of hardcoded codes.
 * Falls back to well-known codes if no mapping is configured.
 */
class AccountMappingService
{
    /** Cache resolved mappings per request */
    private array $cache = [];

    /**
     * Well-known fallback codes used when no mapping row exists.
     * Tenants should configure account_mappings to override these.
     */
    private const FALLBACKS = [
        'cash'                      => '1010',
        'bank'                      => '1020',
        'accounts_receivable'       => '1100',
        'inventory'                 => '1200',
        'accounts_payable'          => '2100',
        'sales_tax_payable'         => '2200',
        'input_tax'                 => '2200',
        'sales_revenue'             => '4100',
        'cogs'                      => '5100',
        'general_expense'           => '5000',
        'depreciation_expense'      => '6100',
        'accumulated_depreciation'  => '1510',
        'gain_loss_disposal'        => '7100',
        'sales_discount'            => '4200',
        'unrealized_fx_gain_loss'   => '7200',
        'realized_fx_gain_loss'     => '7201',
    ];

    /**
     * Resolve a ChartOfAccount by mapping key.
     *
     * @param string      $key          e.g. 'accounts_receivable'
     * @param string|null $documentType e.g. 'invoice', 'bill', 'pos_order'
     * @return ChartOfAccount
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

        // 2. Try default mapping (null document_type)
        $mapping = AccountMapping::where('mapping_key', $key)
            ->whereNull('document_type')
            ->where('is_active', true)
            ->first();

        if ($mapping) {
            return $this->cache[$cacheKey] = $mapping->account;
        }

        // 3. Fallback to well-known code
        $fallbackCode = self::FALLBACKS[$key] ?? null;

        if ($fallbackCode) {
            $account = ChartOfAccount::where('code', $fallbackCode)
                ->where('is_active', true)
                ->first();

            if ($account) {
                return $this->cache[$cacheKey] = $account;
            }
        }

        throw new \RuntimeException(
            "Cannot resolve GL account for mapping key '{$key}'" .
            ($documentType ? " (document type: {$documentType})" : '') .
            ". Please configure account_mappings or ensure a chart-of-accounts entry exists."
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
     * Seed default mappings for a new tenant based on existing COA codes.
     */
    public function seedDefaults(): void
    {
        foreach (self::FALLBACKS as $key => $code) {
            $account = ChartOfAccount::where('code', $code)->first();

            if ($account && !AccountMapping::where('mapping_key', $key)->whereNull('document_type')->exists()) {
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
