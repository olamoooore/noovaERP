<?php

namespace App\Accounting\Services;

use App\Accounting\DTOs\DomainPostingLine;
use App\Accounting\DTOs\DomainPostingRequest;

/**
 * Posting Policy Mapper — prevents operational domains from inventing
 * arbitrary accounting treatment.
 * ADR-005 §12 — deterministic and versioned.
 *
 * Maps business values through posting profiles to produce controlled
 * ledger lines with validation and control-account requirements.
 */
class PostingPolicyMapper
{
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

        if (!$profile) {
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

    private function resolvePostingProfile(string $capability, string $profileCode): ?array
    {
        // In production, this would query AccountingPostingProfile table
        // For now, use built-in profile mappings
        $profiles = $this->getBuiltInProfiles();

        return $profiles[$capability][$profileCode] ?? null;
    }

    private function mapLineThroughProfile(DomainPostingLine $line, array $profile, DomainPostingRequest $request): ?array
    {
        $mapping = $profile['mappings'][$line->accountRole] ?? null;

        if (!$mapping) {
            return null;
        }

        return [
            'account_role' => $line->accountRole,
            'account_id' => $mapping['account_id'],
            'debit' => $mapping['direction'] === 'debit' ? $line->amount : 0,
            'credit' => $mapping['direction'] === 'credit' ? $line->amount : 0,
            'description' => $line->description,
            'currency_code' => $line->currencyCode,
            'dimensions' => array_merge($request->dimensions, $line->dimensions),
            'tax_reference' => $line->taxResultReference,
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
        // Built-in posting profile mappings
        // In production, these would be stored in AccountingPostingProfile table
        return [
            'ACC-003' => [
                'AR_POSTING' => [
                    'id' => 'AR_POSTING',
                    'version' => '1.0',
                    'mappings' => [
                        'RECEIVABLE' => ['account_id' => null, 'direction' => 'debit'],
                        'REVENUE' => ['account_id' => null, 'direction' => 'credit'],
                        'TAX_COLLECTED' => ['account_id' => null, 'direction' => 'credit'],
                    ],
                ],
            ],
            'ACC-004' => [
                'AP_POSTING' => [
                    'id' => 'AP_POSTING',
                    'version' => '1.0',
                    'mappings' => [
                        'EXPENSE' => ['account_id' => null, 'direction' => 'debit'],
                        'TAX_INPUT' => ['account_id' => null, 'direction' => 'debit'],
                        'PAYABLE' => ['account_id' => null, 'direction' => 'credit'],
                    ],
                ],
            ],
            'ACC-005A' => [
                'PAYMENT_EXECUTION' => [
                    'id' => 'PAYMENT_EXECUTION',
                    'version' => '1.0',
                    'mappings' => [
                        'PAYABLE' => ['account_id' => null, 'direction' => 'debit'],
                        'BANK' => ['account_id' => null, 'direction' => 'credit'],
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
