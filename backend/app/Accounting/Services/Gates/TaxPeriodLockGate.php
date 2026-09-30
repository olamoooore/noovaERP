<?php

namespace App\Accounting\Services\Gates;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;
use App\Accounting\Enums\ReasonCode;
use Illuminate\Support\Facades\DB;

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

        if (! $isTaxBearing) {
            return GateEvaluationResult::notApplicable($this->gateCode(), $this->gateOwner());
        }

        $postingDate = (string) ($context['posting_date'] ?? '');
        $tenantId = $context['tenant_id'] ?? null;

        if ($postingDate === '') {
            return GateEvaluationResult::notApplicable($this->gateCode(), $this->gateOwner());
        }

        $period = date('Y-m', strtotime($postingDate));

        $locked = DB::table('tax_period_locks')
            ->where('period', $period)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', (int) $tenantId))
            ->exists();

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
