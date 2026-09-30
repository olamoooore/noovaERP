<?php

namespace App\Accounting\Services\Gates;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;
use App\Accounting\Enums\ReasonCode;
use App\Models\TaxDeterminationResult;

/**
 * Validates tax determination for tax-bearing postings.
 * ADR-005 §32 — Tax Determination Engine must produce a TaxDeterminationResult
 * before tax-bearing entries can be posted.
 *
 * G-05..G-09 (#274): the gate now fails closed on
 *   - a tax-bearing determination whose posting lines lost their tax identity
 *     (must NOT fall through as NOT_APPLICABLE),
 *   - superseded / non-DETERMINED result status,
 *   - a supplied result version older than the persisted current version,
 *   - a caller-expected result version that does not match the supplied one,
 *   - booked tax on the posting lines disagreeing with the determined tax.
 */
class TaxGate implements AuthorityGate
{
    /** Half-up rounding tolerance (1 cent) for booked-vs-determined agreement. */
    private const AMOUNT_TOLERANCE = 0.011;

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
        return '1.1';
    }

    public function evaluate(array $context): GateEvaluationResult
    {
        $postingLines = $context['posting_lines'] ?? [];
        $taxDeterminationResult = $context['tax_determination_result'] ?? null;

        // ── Detect tax-bearing posting lines and the booked tax total ──
        $hasTaxLines = false;
        $bookedTax = 0.0;
        foreach ($postingLines as $line) {
            if (! empty($line['tax_code']) || ! empty($line['tax_id'])) {
                $hasTaxLines = true;
                $bookedTax += max((float) ($line['debit'] ?? 0), (float) ($line['credit'] ?? 0));
            }
        }

        $determinedTax = $this->determinedTaxFrom($taxDeterminationResult);
        $determinationIsTaxBearing = $determinedTax !== null && abs($determinedTax) > 0.005;

        // If no tax-bearing lines: normally NOT_APPLICABLE — but a
        // tax-bearing determination whose posting lines carry no tax identity
        // means the tax attachment was lost between determination and posting.
        // That must BLOCK, never fall through (G-08).
        if (! $hasTaxLines) {
            if ($determinationIsTaxBearing) {
                return GateEvaluationResult::block(
                    $this->gateCode(),
                    $this->gateOwner(),
                    ReasonCode::TAX_RESULT_INVALIDATED->value,
                    $this->gateVersion(),
                    'TaxDeterminationResult expects tax but no posting line carries tax_code/tax_id',
                );
            }

            return GateEvaluationResult::notApplicable($this->gateCode(), $this->gateOwner());
        }

        // If tax-bearing lines exist, a determination result must be present.
        // Issue #42 (HR42-03): emit the canonical ReasonCode — the gate used a
        // non-canonical TAX_DETERMINATION_MISSING string.
        if (! is_array($taxDeterminationResult) || $taxDeterminationResult === []) {
            return GateEvaluationResult::block(
                $this->gateCode(),
                $this->gateOwner(),
                ReasonCode::TAX_RESULT_MISSING->value,
                $this->gateVersion(),
                'Tax-bearing lines require a TaxDeterminationResult before posting',
            );
        }

        // ── G-07: lifecycle status must be usable ──
        $status = $taxDeterminationResult['result_status'] ?? null;
        if ($status !== null && $status !== 'DETERMINED') {
            return GateEvaluationResult::block(
                $this->gateCode(),
                $this->gateOwner(),
                $status === 'SUPERSEDED'
                    ? ReasonCode::TAX_RESULT_STALE->value
                    : ReasonCode::TAX_RESULT_INVALIDATED->value,
                $this->gateVersion(),
                'TaxDeterminationResult is not usable: status '.$status,
            );
        }

        if (array_key_exists('superseded_by', $taxDeterminationResult) && $taxDeterminationResult['superseded_by'] !== null) {
            return GateEvaluationResult::block(
                $this->gateCode(),
                $this->gateOwner(),
                ReasonCode::TAX_RESULT_STALE->value,
                $this->gateVersion(),
                'TaxDeterminationResult has been superseded by a newer determination',
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
                ReasonCode::TAX_RESULT_MISSING->value,
                $this->gateVersion(),
                'TaxDeterminationResult must include jurisdiction and tax_amount',
            );
        }

        // ── G-08: caller-expected result version must match ──
        $expectedVersion = $context['expected_tax_result_version'] ?? null;
        $suppliedVersion = $taxDeterminationResult['result_version'] ?? null;

        if ($expectedVersion !== null && $suppliedVersion !== null && (int) $expectedVersion !== (int) $suppliedVersion) {
            return GateEvaluationResult::block(
                $this->gateCode(),
                $this->gateOwner(),
                ReasonCode::TAX_RESULT_STALE->value,
                $this->gateVersion(),
                'Expected determination v'.$expectedVersion.' but posting supplies v'.$suppliedVersion,
            );
        }

        // ── G-08: supplied snapshot must still be the current version ──
        $staleReason = $this->checkPersistedStaleness($context, $taxDeterminationResult);
        if ($staleReason !== null) {
            return GateEvaluationResult::block(
                $this->gateCode(),
                $this->gateOwner(),
                ReasonCode::TAX_RESULT_STALE->value,
                $this->gateVersion(),
                $staleReason,
            );
        }

        // ── G-08: booked tax must equal determined tax ──
        if ($determinedTax !== null) {
            $bookedRounded = round($bookedTax, 2);

            // Reverse charge is a paired self-assessed pattern: the SAME
            // determined tax posts twice (reclaim + payable), so booked is
            // exactly 2x determined by design (Phase-3 creditability contract).
            $reverseCharge = $this->hasReverseChargeTreatment($taxDeterminationResult);
            $rcPaired = $reverseCharge
                && abs($bookedRounded - ($determinedTax * 2)) <= self::AMOUNT_TOLERANCE;

            if (abs($bookedRounded - $determinedTax) > self::AMOUNT_TOLERANCE && ! $rcPaired) {
                return GateEvaluationResult::block(
                    $this->gateCode(),
                    $this->gateOwner(),
                    ReasonCode::TAX_TOTAL_INCONSISTENT->value,
                    $this->gateVersion(),
                    'Booked tax '.number_format($bookedRounded, 2, '.', '').' differs from determined tax '.number_format($determinedTax, 2, '.', ''),
                );
            }
        }

        return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
    }

    /**
     * True when any determination line carries a reverse-charge treatment.
     */
    private function hasReverseChargeTreatment(array $taxDeterminationResult): bool
    {
        $lines = $taxDeterminationResult['tax_lines'] ?? [];

        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (($line['treatment'] ?? null) === 'reverse_charge') {
                    return true;
                }
            }
        }

        return ($taxDeterminationResult['treatment'] ?? null) === 'reverse_charge';
    }

    /**
     * Total determined tax from the (legacy-shaped or canonical) result array.
     */
    private function determinedTaxFrom(mixed $taxDeterminationResult): ?float
    {
        if (! is_array($taxDeterminationResult)) {
            return null;
        }

        $lines = $taxDeterminationResult['tax_lines'] ?? null;
        $topLevel = isset($taxDeterminationResult['tax_amount'])
            ? round((float) $taxDeterminationResult['tax_amount'], 2)
            : null;

        if (is_array($lines) && $lines !== []) {
            $lineSum = round(array_sum(array_map(
                fn ($line) => (float) ($line['tax_amount'] ?? 0),
                $lines,
            )), 2);

            // Legacy-shaped fixtures carry the amount only at the top level.
            if ($lineSum === 0.0 && $topLevel !== null && $topLevel !== 0.0) {
                return $topLevel;
            }

            return $lineSum;
        }

        return $topLevel;
    }

    /**
     * G-08: the supplied snapshot must be the current determination for the
     * SOURCE DOCUMENT — a re-determined document invalidates older snapshots.
     *
     * Compared against source_record_type/source_record_id (not the
     * correlation uuid) so the check is about document authority. Hand-built
     * requests without a persisted determination for their document id are
     * skipped (still validated on status/version/amount above).
     */
    private function checkPersistedStaleness(array $context, array $result): ?string
    {
        $tenantId = $context['tenant_id'] ?? null;
        $documentType = $context['record_type'] ?? null;
        $documentId = $context['record_id'] ?? null;
        $suppliedVersion = $result['result_version'] ?? null;

        if (! $tenantId || ! $documentType || ! $documentId || $suppliedVersion === null) {
            return null;
        }

        $current = TaxDeterminationResult::where('tenant_id', (string) $tenantId)
            ->where('source_record_type', (string) $documentType)
            ->where('source_record_id', (int) $documentId)
            ->orderByDesc('result_version')
            ->first();

        if (! $current) {
            return null;
        }

        if ($current->superseded_by !== null || $current->result_status !== 'DETERMINED') {
            return 'Persisted determination for '.$documentType.'#'.$documentId.' is '.$current->result_status;
        }

        if ((int) $suppliedVersion < (int) $current->result_version) {
            return 'Supplied determination v'.$suppliedVersion.' is older than the current v'.$current->result_version;
        }

        return null;
    }
}
