<?php

namespace App\Accounting\Services;

use App\Accounting\DTOs\DomainPostingLine;
use App\Accounting\DTOs\DomainPostingRequest;
use Illuminate\Support\Facades\DB;

/**
 * Posting Policy Mapper — prevents operational domains from inventing
 * arbitrary accounting treatment.
 * ADR-005 §12 — deterministic and versioned.
 *
 * Maps business values through posting profiles (persisted in
 * accounting_posting_profiles) to produce controlled ledger lines with
 * validation and control-account requirements. Falls back to built-in
 * defaults when no DB row is present.
 */
class PostingPolicyMapper
{
    public function __construct(
        private readonly AccountRoleResolver $roleResolver,
    ) {}

    /**
     * Resolve a DomainPostingRequest into concrete ledger lines.
     *
     * ADR-005 §12:
     * Input: source_capability, posting_profile_code, business_values,
     *        entity/accounting_book, currency_context, tax_result_reference, dimensions
     * Output: resolved_posting_rule_id, ledger_lines[], validation_requirements[],
     *         control_account_requirements[]
     */
    public function resolve(DomainPostingRequest $request): PostingResolution
    {
        $resolvedLines = [];
        $validationRequirements = [];
        $controlAccountRequirements = [];

        // ── Resolve posting profile ────────────────────────────
        $profile = $this->resolvePostingProfile(
            $request->sourceCapability,
            $request->postingProfileCode,
        );

        if (! $profile) {
            return PostingResolution::error("Posting profile '{$request->postingProfileCode}' not found for capability '{$request->sourceCapability}'.");
        }

        // ── Map each business line through the profile ─────────
        foreach ($request->lines as $line) {
            $mapped = $this->mapLineThroughProfile($line, $profile, $request);

            if ($mapped === null) {
                return PostingResolution::error("No mapping found for account role '{$line->accountRole}' in profile '{$request->postingProfileCode}'.");
            }

            $resolvedLines[] = $mapped;
        }

        // ── Add control account requirements ───────────────────
        $controlAccountRequirements = $this->buildControlAccountRequirements($request->sourceCapability, $resolvedLines);

        // ── Add validation requirements ────────────────────────
        $validationRequirements = $this->buildValidationRequirements($request);

        return PostingResolution::success(
            postingRuleId: $profile['id'] ?? 'default',
            postingRuleVersion: $profile['version'] ?? '1.0',
            ledgerLines: $resolvedLines,
            validationRequirements: $validationRequirements,
            controlAccountRequirements: $controlAccountRequirements,
        );
    }

    /**
     * Resolve a posting profile from the database catalog. Falls back to
     * built-in defaults when no active DB row is found (back-compat).
     *
     * The catalog lives in the TENANT schema — the create migration is
     * `database/migrations/tenant/2026_08_29_000001_...`, and rows carry
     * tenant/entity/book columns. The table name must therefore stay
     * UNQUALIFIED so it resolves through the active tenant `search_path`;
     * a `public.` prefix would look in the central schema, find nothing,
     * and silently degrade every call to the built-in fallback (this was
     * the defect found while auditing ACC-RM-001 on 2026-09-02).
     */
    private function resolvePostingProfile(string $capability, string $profileCode): ?array
    {
        try {
            $row = DB::table('accounting_posting_profiles')
                ->where('source_capability', $capability)
                ->where('posting_profile_code', $profileCode)
                ->where('is_active', true)
                ->orderByDesc('version')
                ->first();
            if ($row) {
                return [
                    'id' => $row->id,
                    'version' => $row->version,
                    'mappings' => json_decode($row->mappings, true) ?? [],
                    'control_account_requirements' => json_decode($row->control_account_requirements ?? '{}', true) ?? [],
                ];
            }
        } catch (\Throwable $e) {
            // Table may not exist yet (migration not run) — fall through to built-in
        }

        // Back-compat fallback for tests / environments without the catalog
        $profiles = $this->getBuiltInProfiles();

        return $profiles[$capability][$profileCode] ?? null;
    }

    /**
     * Map a business-value line into a concrete ledger line. Account IDs
     * are resolved via AccountRoleResolver using company_settings defaults.
     */
    private function mapLineThroughProfile(DomainPostingLine $line, array $profile, DomainPostingRequest $request): ?array
    {
        $mapping = $profile['mappings'][$line->accountRole] ?? null;

        if (! $mapping) {
            return null;
        }

        // Zero-amount business values carry no accounting effect, but we
        // still emit the line so the PostingEngine's balance check can
        // confirm the entry balances (e.g. a zero-amount invoice produces
        // balanced 0/0 ledger lines, which is valid). For mixed-amount
        // requests, zero-amount lines are still emitted with debit=credit=0
        // so cardinality matches the resolved lines. The legacy manual-line
        // builders that conditionally appended tax/shipping/COGS lines
        // already filtered these out before reaching the mapper; the
        // mapper-level filter is therefore removed to avoid false negatives
        // for wholly-zero business events.
        // (Comment preserved for context — the early-return is intentional.)

        // Resolve concrete account_id via AccountRoleResolver (uses
        // entity_account_roles mapped to chart_of_accounts, falling back to
        // the legacy AccountMappingService). The source record type is
        // forwarded as documentType so keys without a direct role mapping
        // (sales_revenue, shipping_revenue — KEY_TO_ROLE null entries)
        // resolve identically to the pre-migration manual-line path.
        $accountId = null;
        try {
            $accountId = $this->roleResolver->resolveId($line->accountRole, $request->sourceRecordType);
        } catch (\Throwable $e) {
            // No-op — leave null, PostingEngine will surface error
        }

        return [
            'account_role' => $line->accountRole,
            'account_id' => $accountId,
            'debit' => $mapping['direction'] === 'debit' ? $line->amount : 0,
            'credit' => $mapping['direction'] === 'credit' ? $line->amount : 0,
            'description' => $line->description,
            'currency_code' => $line->currencyCode,
            'original_amount' => $line->originalAmount,
            'exchange_rate' => $line->exchangeRate,
            'dimensions' => array_merge($request->dimensions, $line->dimensions),
            'tax_reference' => $line->taxResultReference,
            'tax_code' => $line->taxCode,
            'tax_id' => $line->taxId,
        ];
    }

    private function buildControlAccountRequirements(string $sourceCapability, array $lines): array
    {
        $requirements = [];

        foreach ($lines as $line) {
            $accountId = $line['account_id'] ?? null;
            if ($accountId) {
                $requirements[] = [
                    'account_id' => $accountId,
                    'source_capability' => $sourceCapability,
                    'requires_exceptional_authority' => false,
                ];
            }
        }

        return $requirements;
    }

    private function buildValidationRequirements(DomainPostingRequest $request): array
    {
        $requirements = [];

        // Tax validation if tax result referenced
        if ($request->taxResultReference) {
            $requirements[] = [
                'type' => 'TAX_REFERENCE',
                'required' => true,
                'reference' => $request->taxResultReference,
            ];
        }

        // Period validation
        $requirements[] = [
            'type' => 'PERIOD_OPEN',
            'required' => true,
            'posting_date' => $request->postingDate,
        ];

        return $requirements;
    }

    private function getBuiltInProfiles(): array
    {
        // Built-in posting profile mappings (fallback only). Account role
        // keys are the canonical names from AccountRoleResolver::KEY_TO_ROLE
        // so resolution works via the role-based pipeline.
        return [
            'ACC-003' => [
                'AR_INVOICE_POST' => [
                    'id' => 'AR_INVOICE_POST',
                    'version' => '1.1',
                    'mappings' => [
                        'accounts_receivable' => ['account_id' => null, 'direction' => 'debit'],
                        'sales_revenue' => ['account_id' => null, 'direction' => 'credit'],
                        'shipping_revenue' => ['account_id' => null, 'direction' => 'credit'],
                        'sales_tax_payable' => ['account_id' => null, 'direction' => 'credit'],
                        'cogs' => ['account_id' => null, 'direction' => 'debit'],
                        'inventory' => ['account_id' => null, 'direction' => 'credit'],
                    ],
                ],
                'AR_PAYMENT_RECEIPT' => [
                    'id' => 'AR_PAYMENT_RECEIPT',
                    'version' => '1.0',
                    'mappings' => [
                        'bank' => ['account_id' => null, 'direction' => 'debit'],
                        'accounts_receivable' => ['account_id' => null, 'direction' => 'credit'],
                    ],
                ],
            ],
            'ACC-004' => [
                'AP_BILL_POST' => [
                    'id' => 'AP_BILL_POST',
                    'version' => '1.0',
                    'mappings' => [
                        'general_expense' => ['account_id' => null, 'direction' => 'debit'],
                        'input_tax' => ['account_id' => null, 'direction' => 'debit'],
                        'accounts_payable' => ['account_id' => null, 'direction' => 'credit'],
                    ],
                ],
                'AP_VENDOR_PAYMENT' => [
                    'id' => 'AP_VENDOR_PAYMENT',
                    'version' => '1.0',
                    'mappings' => [
                        'accounts_payable' => ['account_id' => null, 'direction' => 'debit'],
                        'bank' => ['account_id' => null, 'direction' => 'credit'],
                    ],
                ],
            ],
            'ACC-005A' => [
                'PAYMENT_EXECUTION' => [
                    'id' => 'PAYMENT_EXECUTION',
                    'version' => '1.0',
                    'mappings' => [
                        'accounts_payable' => ['account_id' => null, 'direction' => 'debit'],
                        'accounts_receivable' => ['account_id' => null, 'direction' => 'debit'],
                        'bank' => ['account_id' => null, 'direction' => 'credit'],
                    ],
                ],
                'PAYMENT_REFUND' => [
                    'id' => 'PAYMENT_REFUND',
                    'version' => '1.0',
                    'mappings' => [
                        'accounts_receivable' => ['account_id' => null, 'direction' => 'debit'],
                        'accounts_payable' => ['account_id' => null, 'direction' => 'credit'],
                        'bank' => ['account_id' => null, 'direction' => 'credit'],
                    ],
                ],
            ],
            'ACC-006' => [
                'BANK_RECEIPT' => [
                    'id' => 'BANK_RECEIPT',
                    'version' => '1.0',
                    'mappings' => [
                        'bank' => ['account_id' => null, 'direction' => 'debit'],
                        'cash' => ['account_id' => null, 'direction' => 'debit'],
                    ],
                ],
            ],
            'ACC-007' => [
                'ASSET_DEPRECIATION' => [
                    'id' => 'ASSET_DEPRECIATION',
                    'version' => '1.0',
                    'mappings' => [
                        'depreciation_expense' => ['account_id' => null, 'direction' => 'debit'],
                        'accumulated_depreciation' => ['account_id' => null, 'direction' => 'credit'],
                    ],
                ],
                'ASSET_CAPITALISATION' => [
                    'id' => 'ASSET_CAPITALISATION',
                    'version' => '1.0',
                    'mappings' => [
                        'inventory' => ['account_id' => null, 'direction' => 'debit'],
                        'cash' => ['account_id' => null, 'direction' => 'credit'],
                    ],
                ],
            ],
            'ACC-008' => [
                'FX_REVALUATION' => [
                    'id' => 'FX_REVALUATION',
                    'version' => '1.0',
                    'mappings' => [
                        'unrealized_fx_gain_loss' => ['account_id' => null, 'direction' => 'debit'],
                        'realized_fx_gain_loss' => ['account_id' => null, 'direction' => 'credit'],
                    ],
                ],
            ],
            'ACC-009' => [
                'TAX_ADJUSTMENT' => [
                    'id' => 'TAX_ADJUSTMENT',
                    'version' => '1.0',
                    'mappings' => [
                        'sales_tax_payable' => ['account_id' => null, 'direction' => 'debit'],
                        'input_tax' => ['account_id' => null, 'direction' => 'credit'],
                    ],
                ],
            ],
            // Issue #105: procurement goods receipt recognition. Account roles
            // resolve through AccountRoleResolver/AccountMappingService using the
            // canonical 'inventory' and 'grni' keys.
            'ACC-012' => [
                'INVENTORY_RECEIPT' => [
                    'id' => 'INVENTORY_RECEIPT',
                    'version' => '1.0',
                    'mappings' => [
                        'inventory' => ['account_id' => null, 'direction' => 'debit'],
                        'grni' => ['account_id' => null, 'direction' => 'credit'],
                    ],
                ],
                // Issue #176: a completed delivery order issues inventory and must
                // recognise COGS even before (or without) the invoice posting.
                'DELIVERY_ORDER_COGS' => [
                    'id' => 'DELIVERY_ORDER_COGS',
                    'version' => '1.0',
                    'mappings' => [
                        'cogs' => ['account_id' => null, 'direction' => 'debit'],
                        'inventory' => ['account_id' => null, 'direction' => 'credit'],
                    ],
                ],
                'DELIVERY_ORDER_COGS_REVERSAL' => [
                    'id' => 'DELIVERY_ORDER_COGS_REVERSAL',
                    'version' => '1.0',
                    'mappings' => [
                        'inventory' => ['account_id' => null, 'direction' => 'debit'],
                        'cogs' => ['account_id' => null, 'direction' => 'credit'],
                    ],
                ],
            ],
            'ACC-011' => [
                'CLOSE_PERIODIC' => [
                    'id' => 'CLOSE_PERIODIC',
                    'version' => '1.0',
                    'mappings' => [
                        'sales_revenue' => ['account_id' => null, 'direction' => 'debit'],
                        'general_expense' => ['account_id' => null, 'direction' => 'credit'],
                        'retained_earnings' => ['account_id' => null, 'direction' => 'credit'],
                    ],
                ],
            ],
        ];
    }
}

class PostingResolution
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $postingRuleId = null,
        public readonly ?string $postingRuleVersion = null,
        public readonly array $ledgerLines = [],
        public readonly array $validationRequirements = [],
        public readonly array $controlAccountRequirements = [],
        public readonly ?string $error = null,
    ) {}

    public static function success(
        string $postingRuleId,
        string $postingRuleVersion,
        array $ledgerLines,
        array $validationRequirements = [],
        array $controlAccountRequirements = [],
    ): self {
        return new self(
            success: true,
            postingRuleId: $postingRuleId,
            postingRuleVersion: $postingRuleVersion,
            ledgerLines: $ledgerLines,
            validationRequirements: $validationRequirements,
            controlAccountRequirements: $controlAccountRequirements,
        );
    }

    public static function error(string $error): self
    {
        return new self(success: false, error: $error);
    }
}
