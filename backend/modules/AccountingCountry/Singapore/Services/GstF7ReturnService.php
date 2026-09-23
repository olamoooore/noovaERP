<?php

namespace Modules\AccountingCountry\Singapore\Services;

use App\Models\StatutoryReturnFiling;

/**
 * Issue #265 (split from #93): IRAS GST F7 (Disclosure of Errors).
 *
 * F7 discloses the difference between a previously FILED F5 and the corrected
 * figures. The filed snapshot comes from statutory_return_filings; the
 * corrected figures are recomputed from the same tax_transaction_details audit
 * source F5 uses, entity-scoped identically.
 */
class GstF7ReturnService
{
    public function __construct(
        private readonly GstF5ReturnService $f5Service,
    ) {}

    /**
     * @param  string  $period  The previously filed period (YYYY-MM or YYYY-Qn).
     */
    public function disclose(string $period, ?int $legalEntityId = null): array
    {
        $filing = StatutoryReturnFiling::query()
            ->where('country', 'SG')
            ->where('form', 'GST F5')
            ->where('period', $period)
            ->where('entity_key', StatutoryReturnFiling::entityKey($legalEntityId))
            ->latest('filed_at')
            ->first();

        if (! $filing) {
            throw new \RuntimeException(
                "No filed GST F5 exists for period {$period}; F7 can only disclose errors against a filed return."
            );
        }

        $corrected = $this->f5Service->generate($period, $legalEntityId);
        $filedBoxes = $filing->boxes ?? [];
        $correctedBoxes = $corrected['boxes'] ?? [];

        $deltas = [];
        foreach ($correctedBoxes as $box => $value) {
            $filed = round((float) ($filedBoxes[$box] ?? 0), 2);
            $deltas[$box] = round((float) $value - $filed, 2);
        }

        $outputTaxError = $deltas['box_6_output_tax_due'] ?? 0.0;
        $inputTaxError = $deltas['box_7_input_tax_and_refunds'] ?? 0.0;

        return [
            'form' => 'GST F7',
            'jurisdiction' => 'SG',
            'authority' => 'IRAS',
            'period' => $period,
            'currency' => 'SGD',
            'baseline' => [
                'filed_at' => $filing->filed_at?->toIso8601String(),
                'filed_by' => $filing->filed_by,
                'boxes' => $filedBoxes,
            ],
            'corrected' => [
                'boxes' => $correctedBoxes,
            ],
            // Positive delta = under-declared (additional tax payable); negative
            // = over-declared (refund/credit).
            'error_amounts' => $deltas,
            'classification' => [
                'output_tax' => $this->classify($outputTaxError),
                'input_tax' => $this->classify($inputTaxError),
                'standard_rated_supplies' => $this->classify($deltas['box_1_standard_rated_supplies'] ?? 0.0),
                'taxable_purchases' => $this->classify($deltas['box_5_taxable_purchases'] ?? 0.0),
            ],
            'net_gst_error' => $deltas['box_8_net_gst_payable'] ?? 0.0,
        ];
    }

    private function classify(float $delta): string
    {
        if (abs($delta) < 0.01) {
            return 'no_error';
        }

        return $delta > 0 ? 'under_declared' : 'over_declared';
    }
}
