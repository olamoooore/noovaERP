<?php

namespace App\Accounting\Services\Gates;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;

/**
 * Validates tax determination for tax-bearing postings.
 * ADR-005 §32 — Tax Determination Engine must produce a TaxDeterminationResult
 * before tax-bearing entries can be posted.
 */
class TaxGate implements AuthorityGate
{
    public function gateCode(): string
    {
        return 'TAX_DETERMINATION';
    }

    public function gateOwner(): string
    {
        return 'ACC-009';
    }

    public function gateVersion(): string
    {
        return '1.0';
    }

    public function evaluate(array $context): GateEvaluationResult
    {
        $postingLines = $context['posting_lines'] ?? [];
        $taxDeterminationResult = $context['tax_determination_result'] ?? null;

        // Check if any lines have tax codes
        $hasTaxLines = false;
        foreach ($postingLines as $line) {
            if (!empty($line['tax_code']) || !empty($line['tax_id'])) {
                $hasTaxLines = true;
                break;
            }
        }

        // If no tax-bearing lines, gate is not applicable
        if (!$hasTaxLines) {
            return GateEvaluationResult::notApplicable($this->gateCode(), $this->gateOwner());
        }

        // If tax-bearing lines exist, a determination result must be present.
        // Issue #42 (HR42-03): emit the canonical ReasonCode — the gate used a
        // non-canonical TAX_DETERMINATION_MISSING string.
        if (!$taxDeterminationResult) {
            return GateEvaluationResult::block(
                $this->gateCode(),
                $this->gateOwner(),
                \App\Accounting\Enums\ReasonCode::TAX_RESULT_MISSING->value,
                $this->gateVersion(),
                'Tax-bearing lines require a TaxDeterminationResult before posting',
            );
        }

        // Validate determination result has required fields
        $jurisdiction = $taxDeterminationResult['jurisdiction'] ?? null;
        $taxAmount = $taxDeterminationResult['tax_amount'] ?? null;

        if ($jurisdiction === null || $taxAmount === null) {
            // A result without its required fields is not a usable
            // determination — same canonical TAX_RESULT_MISSING code.
            return GateEvaluationResult::block(
                $this->gateCode(),
                $this->gateOwner(),
                \App\Accounting\Enums\ReasonCode::TAX_RESULT_MISSING->value,
                $this->gateVersion(),
                'TaxDeterminationResult must include jurisdiction and tax_amount',
            );
        }

        return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
    }
}
