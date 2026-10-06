<?php

namespace App\Services;

use App\Models\BonusSchemeUsage;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Issue #185: hospital/doctor performance reporting for medical cases.
 *
 * Hospital = medical_cases.customer_id (the billed customer).
 * Doctor   = medical_cases.doctor_contact_id (a customer_contacts row) —
 *            reported per contact row, NOT rolled up across hospitals.
 * Metrics  = case count, invoiced value, bonus-scheme case count and the
 *            product/procedure mix, broken down by month within the range.
 *
 * Issue #574 (MC10): the invoiced value is the linked invoice line's net
 * amount (after line discount, excluding tax) for the case's share of that
 * line, minus posted credit notes against the line, converted to the base
 * currency with the invoice's exchange-rate snapshot. FOC units are reported
 * separately (count and list value) and never add to the invoiced value.
 */
class MedicalCaseReportingService
{
    /**
     * @param  int[]|null  $accessibleEntityIds  Issue #575: legal entities the
     *                                           caller may see (null = unscoped)
     */
    public function performance(Carbon $from, Carbon $to, string $dateField = 'case_date', ?array $accessibleEntityIds = null): array
    {
        $dateField = in_array($dateField, ['case_date', 'procedure_date'], true) ? $dateField : 'case_date';

        $cases = MedicalCase::query()
            ->with([
                'customer:id,name',
                'doctorContact:id,name',
                'items.product:id,name,sku,price',
                'items.invoiceItem.invoice',
                'items.salesOrderItem.salesOrder',
            ])
            ->whereNotNull($dateField)
            ->whereBetween($dateField, [$from->toDateString(), $to->toDateString()])
            ->when($accessibleEntityIds !== null, fn ($query) => $query->accessibleBy($accessibleEntityIds))
            ->get();

        $usageCaseItemIds = BonusSchemeUsage::query()
            ->whereIn('medical_case_item_id', $cases->flatMap->items->pluck('id'))
            ->where('status', '!=', 'reversed')
            ->pluck('medical_case_item_id')
            ->unique()
            ->flip();

        // Issue #574: posted credit notes against each linked invoice line
        // (net of tax, in the invoice's currency — credit notes inherit it).
        $creditedByLine = $this->creditedNetByInvoiceLine(
            $cases->flatMap->items->pluck('invoice_item_id')->filter()->unique()->values()->all()
        );
        $this->warnings = [];

        $byHospital = [];
        $hospitalTotals = [];
        $byDoctor = [];
        $productMix = [];

        foreach ($cases as $case) {
            // Issue #609: a cancelled case used nothing (its delivery is
            // reversed, #189) and is not part of the performance report.
            if ($case->status === 'cancelled') {
                continue;
            }

            $month = Carbon::parse($case->{$dateField})->format('Y-m');
            $invoicedValue = 0.0;
            $focUnits = 0.0;
            $focListValue = 0.0;
            $bonusCase = false;

            foreach ($case->items as $item) {
                if ($item->is_foc) {
                    // Issue #574: FOC units are redeemed free of charge —
                    // reported as a count and at the product's list price.
                    $units = (float) $item->delivered_qty;
                    $focUnits += $units;
                    $focListValue += $units * (float) ($item->product?->price ?? 0);
                } else {
                    $invoicedValue += $this->itemInvoicedBaseValue($item, $creditedByLine);
                }

                if (isset($usageCaseItemIds[$item->id])) {
                    $bonusCase = true;
                }

                $productKey = $item->product_id ?: 'unknown';
                if (! isset($productMix[$productKey])) {
                    $productMix[$productKey] = [
                        'product_id' => $item->product_id,
                        'product_name' => $item->product?->name,
                        'sku' => $item->product?->sku,
                        'quantity' => 0.0,
                    ];
                }
                // Issue #609: units used, not planned. delivered_qty is only
                // recorded from a completed delivery-order line (#562 route,
                // #564 import), so it is the verified usage — allocated_qty
                // is the reservation and over-counts unused stock.
                $productMix[$productKey]['quantity'] += (float) $item->delivered_qty;
            }

            $invoicedValue = round($invoicedValue, 2);
            $focListValue = round($focListValue, 2);

            // Hospital (per month)
            $hospitalKey = $case->customer_id.'|'.$month;
            $byHospital[$hospitalKey] ??= [
                'customer_id' => $case->customer_id,
                'customer_name' => $case->customer?->name,
                'month' => $month,
                'case_count' => 0,
                'invoiced_value' => 0.0,
                'bonus_case_count' => 0,
                'foc_units' => 0.0,
                'foc_list_value' => 0.0,
            ];
            $byHospital[$hospitalKey]['case_count']++;
            $byHospital[$hospitalKey]['invoiced_value'] += $invoicedValue;
            $byHospital[$hospitalKey]['bonus_case_count'] += $bonusCase ? 1 : 0;
            $byHospital[$hospitalKey]['foc_units'] += $focUnits;
            $byHospital[$hospitalKey]['foc_list_value'] += $focListValue;

            // Hospital (total)
            $hospitalTotals[$case->customer_id] ??= [
                'customer_id' => $case->customer_id,
                'customer_name' => $case->customer?->name,
                'case_count' => 0,
                'invoiced_value' => 0.0,
                'bonus_case_count' => 0,
                'foc_units' => 0.0,
                'foc_list_value' => 0.0,
            ];
            $hospitalTotals[$case->customer_id]['case_count']++;
            $hospitalTotals[$case->customer_id]['invoiced_value'] += $invoicedValue;
            $hospitalTotals[$case->customer_id]['bonus_case_count'] += $bonusCase ? 1 : 0;
            $hospitalTotals[$case->customer_id]['foc_units'] += $focUnits;
            $hospitalTotals[$case->customer_id]['foc_list_value'] += $focListValue;

            // Doctor (per customer_contacts row, per month)
            if ($case->doctor_contact_id) {
                $doctorKey = $case->doctor_contact_id.'|'.$month;
                $byDoctor[$doctorKey] ??= [
                    'doctor_contact_id' => $case->doctor_contact_id,
                    'doctor_name' => $case->doctorContact?->name,
                    'customer_id' => $case->customer_id,
                    'customer_name' => $case->customer?->name,
                    'month' => $month,
                    'case_count' => 0,
                    'invoiced_value' => 0.0,
                    'bonus_case_count' => 0,
                    'foc_units' => 0.0,
                    'foc_list_value' => 0.0,
                ];
                $byDoctor[$doctorKey]['case_count']++;
                $byDoctor[$doctorKey]['invoiced_value'] += $invoicedValue;
                $byDoctor[$doctorKey]['bonus_case_count'] += $bonusCase ? 1 : 0;
                $byDoctor[$doctorKey]['foc_units'] += $focUnits;
                $byDoctor[$doctorKey]['foc_list_value'] += $focListValue;
            }
        }

        $round = function (array $row): array {
            $row['invoiced_value'] = round($row['invoiced_value'], 2);
            $row['foc_units'] = round($row['foc_units'], 4);
            $row['foc_list_value'] = round($row['foc_list_value'], 2);

            return $row;
        };

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'date_field' => $dateField,
            ],
            'by_hospital' => array_map($round, array_values($byHospital)),
            'hospital_totals' => array_map($round, array_values($hospitalTotals)),
            'by_doctor' => array_map($round, array_values($byDoctor)),
            'product_mix' => array_values(array_map(function (array $row) {
                $row['quantity'] = round($row['quantity'], 4);

                return $row;
            }, $productMix)),
            // Issue #574: lines whose value could not be taken from a linked
            // invoice line or converted to base currency are listed here
            // instead of being silently valued.
            'warnings' => array_values(array_unique($this->warnings)),
        ];
    }

    /** @var string[] */
    private array $warnings = [];

    /**
     * Issue #574: posted credit-note net (line_total − tax) per invoice line.
     *
     * @param  int[]  $invoiceLineIds
     * @return array<int, float>
     */
    private function creditedNetByInvoiceLine(array $invoiceLineIds): array
    {
        if ($invoiceLineIds === []) {
            return [];
        }

        return DB::table('customer_credit_note_lines as cnl')
            ->join('customer_credit_notes as cn', 'cn.id', '=', 'cnl.credit_note_id')
            ->whereIn('cnl.invoice_line_id', $invoiceLineIds)
            ->where('cn.status', 'posted')
            ->groupBy('cnl.invoice_line_id')
            ->selectRaw('cnl.invoice_line_id, SUM(cnl.line_total - COALESCE(cnl.tax_amount, 0)) as credited')
            ->pluck('credited', 'invoice_line_id')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    /**
     * Issue #574: the base-currency invoiced value of one non-FOC case line.
     *
     * Linked line: the case's share (invoiced_qty / line quantity) of the
     * invoice line's net amount minus posted credit notes, converted with the
     * invoice's rate snapshot. A cancelled/voided/reversed invoice is worth 0.
     *
     * Legacy line with no invoice line link (recorded before #566 made the
     * link mandatory): valued at the case line's price snapshot (#563, taken
     * from the sales order line) in the sales order's currency, converted to
     * base, and flagged in the report warnings.
     *
     * @param  array<int, float>  $creditedByLine
     */
    private function itemInvoicedBaseValue(MedicalCaseItem $item, array $creditedByLine): float
    {
        $invoicedQty = (float) $item->invoiced_qty;

        if (round($invoicedQty, 4) <= 0) {
            return 0.0;
        }

        $line = $item->invoiceItem;

        if (! $line) {
            $this->warnings[] = "Case line {$item->id} has no linked invoice line; valued at its sales order price snapshot.";

            $order = $item->salesOrderItem?->salesOrder;
            $amount = $invoicedQty * (float) $item->unit_price;

            return $order ? ($this->toBase($order, $amount, $item) ?? 0.0) : $amount;
        }

        $invoice = $line->invoice;

        if (! $invoice
            || $invoice->status === 'cancelled'
            || in_array($invoice->lifecycle_status, ['cancelled', 'voided', 'reversed', 'superseded'], true)) {
            return 0.0;
        }

        $lineQty = (float) $line->quantity;

        if ($lineQty <= 0) {
            return 0.0;
        }

        $share = min($invoicedQty, $lineQty) / $lineQty;
        $lineNet = (float) $line->line_total - (float) ($line->tax_amount ?? 0);
        $credited = (float) ($creditedByLine[$line->id] ?? 0);

        return $this->toBase($invoice, ($lineNet - $credited) * $share, $item) ?? 0.0;
    }

    /**
     * Base-currency amount via the document's own rate snapshot (the
     * SalesApprovalGate #649 convention), falling back to ExchangeRateService
     * at the document date. When no rate exists the line is reported in the
     * warnings and contributes nothing (never a cross-currency sum).
     */
    private function toBase(Model $document, float $amount, MedicalCaseItem $item): ?float
    {
        $base = app(SalesApprovalGate::class)->baseCurrencyAmount($document, $amount);

        if ($base !== null) {
            return $base;
        }

        try {
            $date = $document->invoice_date ?? $document->order_date ?? null;

            return (float) app(ExchangeRateService::class)->convert(
                $amount,
                strtoupper((string) $document->currency_code),
                strtoupper((string) $document->base_currency_code),
                $date ? \Carbon\Carbon::parse($date)->toDateString() : null,
            )['converted_amount'];
        } catch (\Throwable $e) {
            $this->warnings[] = "Case line {$item->id} could not be converted from {$document->currency_code} to {$document->base_currency_code}: {$e->getMessage()}";

            return null;
        }
    }
}
