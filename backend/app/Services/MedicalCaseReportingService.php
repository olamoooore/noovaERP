<?php

namespace App\Services;

use App\Models\BonusSchemeUsage;
use App\Models\MedicalCase;
use Carbon\Carbon;

/**
 * Issue #185: hospital/doctor performance reporting for medical cases.
 *
 * Hospital = medical_cases.customer_id (the billed customer).
 * Doctor   = medical_cases.doctor_contact_id (a customer_contacts row) —
 *            reported per contact row, NOT rolled up across hospitals.
 * Metrics  = case count, invoiced value, bonus-scheme case count and the
 *            product/procedure mix, broken down by month within the range.
 */
class MedicalCaseReportingService
{
    public function performance(Carbon $from, Carbon $to, string $dateField = 'case_date'): array
    {
        $dateField = in_array($dateField, ['case_date', 'procedure_date'], true) ? $dateField : 'case_date';

        $cases = MedicalCase::query()
            ->with(['customer:id,name', 'doctorContact:id,name', 'items.product:id,name,sku'])
            ->whereNotNull($dateField)
            ->whereBetween($dateField, [$from->toDateString(), $to->toDateString()])
            ->get();

        $usageCaseItemIds = BonusSchemeUsage::query()
            ->whereIn('medical_case_item_id', $cases->flatMap->items->pluck('id'))
            ->where('status', '!=', 'reversed')
            ->pluck('medical_case_item_id')
            ->unique()
            ->flip();

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
            $bonusCase = false;

            foreach ($case->items as $item) {
                $invoicedValue += (float) $item->invoiced_qty * (float) $item->unit_price;

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

            // Hospital (per month)
            $hospitalKey = $case->customer_id.'|'.$month;
            $byHospital[$hospitalKey] ??= [
                'customer_id' => $case->customer_id,
                'customer_name' => $case->customer?->name,
                'month' => $month,
                'case_count' => 0,
                'invoiced_value' => 0.0,
                'bonus_case_count' => 0,
            ];
            $byHospital[$hospitalKey]['case_count']++;
            $byHospital[$hospitalKey]['invoiced_value'] += $invoicedValue;
            $byHospital[$hospitalKey]['bonus_case_count'] += $bonusCase ? 1 : 0;

            // Hospital (total)
            $hospitalTotals[$case->customer_id] ??= [
                'customer_id' => $case->customer_id,
                'customer_name' => $case->customer?->name,
                'case_count' => 0,
                'invoiced_value' => 0.0,
                'bonus_case_count' => 0,
            ];
            $hospitalTotals[$case->customer_id]['case_count']++;
            $hospitalTotals[$case->customer_id]['invoiced_value'] += $invoicedValue;
            $hospitalTotals[$case->customer_id]['bonus_case_count'] += $bonusCase ? 1 : 0;

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
                ];
                $byDoctor[$doctorKey]['case_count']++;
                $byDoctor[$doctorKey]['invoiced_value'] += $invoicedValue;
                $byDoctor[$doctorKey]['bonus_case_count'] += $bonusCase ? 1 : 0;
            }
        }

        $round = function (array $row): array {
            $row['invoiced_value'] = round($row['invoiced_value'], 2);

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
        ];
    }
}
