<?php

namespace App\Accounting\Services\Gates;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;
use App\Accounting\Enums\ReasonCode;

/**
 * MRD R7.4: a CLOSED TAX PERIOD is immutable evidence. Tax-bearing postings
 * dated inside a locked reporting month fail closed — amendments must be
 * posted in an OPEN period (adjustment/credit documents), which is exactly
 * how IRAS/LHDN expect errors to be corrected on later returns.
 *
 * Scope: tax-bearing lines only (mirrors TaxGate applicability); non-tax
 * postings are unaffected so closing tax never freezes unrelated ledgers.
 */
class TaxPeriodLockGate implements AuthorityGate
{
    public function gateCode(): string
    {
        return 'TAX_PERIOD_LOCK';
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

        $isTaxBearing = false;
        foreach ($postingLines as $line) {
            if (! empty($line['tax_code']) || ! empty($line['tax_id'])) {
                $isTaxBearing = true;
                break;
            }
        }

        // #278: supply-only documents (zero-rated/exempt bills) carry no GL
        // tax line but DO change the return (F5 box 2/3) — a determination
        // with tax_lines is tax-bearing for lock purposes even without a
        // coded posting line.
        if (! $isTaxBearing) {
            $determination = $context['tax_determination_result'] ?? null;

            if (is_array($determination) && ! empty($determination['tax_lines'])) {
                $isTaxBearing = true;
            }
        }

        if (! $isTaxBearing) {
            return GateEvaluationResult::notApplicable($this->gateCode(), $this->gateOwner());
        }

        $postingDate = (string) ($context['posting_date'] ?? '');
        $tenantId = $context['tenant_id'] ?? null;

        if ($postingDate === '') {
            return GateEvaluationResult::notApplicable($this->gateCode(), $this->gateOwner());
        }

        $period = date('Y-m', strtotime($postingDate));

        // #278: locks are scoped per legal entity (NULL = tenant-wide) and
        // per regime (NULL = every regime). The posting's regime is the
        // jurisdiction of its tax determination; unknown → any lock blocks.
        $entityId = $context['legal_entity_id'] ?? null;
        $determination = $context['tax_determination_result'] ?? null;
        $regime = is_array($determination) ? ($determination['jurisdiction'] ?? null) : null;

        $locked = app(\App\Services\TaxPeriodClosingService::class)->isPeriodLocked(
            $period,
            (int) $tenantId,
            $entityId !== null ? (int) $entityId : null,
            is_string($regime) ? $regime : null,
        );

        if ($locked) {
            return GateEvaluationResult::block(
                $this->gateCode(),
                $this->gateOwner(),
                ReasonCode::TAX_PERIOD_LOCKED->value,
                $this->gateVersion(),
                "Tax period {$period} is closed. Post the amendment (credit/debit note or adjustment) in an open period instead.",
            );
        }

        return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
    }
}
