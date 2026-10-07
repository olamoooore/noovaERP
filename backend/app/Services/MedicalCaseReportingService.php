<?php

namespace App\Services;

use App\Models\BonusSchemeUsage;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use App\Platform\DataSecurity\FieldMaskingService;
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
 *
 * Issue #608 (RPT-F2): the `usage` block is the "Product usage" pivot — one
 * row per legal entity → hospital group → hospital → doctor × product with
 * units used (delivered_qty, #609), FOC units (is_foc lines), paid units
 * (used − FOC), distinct cases and the #574 net value. Legal entity = the
 * case's selling entity (#575; legacy NULL rows fall back to the customer's
 * entity, as scopeAccessibleBy does); hospital group = the customer's
 * top-level customers.parent_id ancestor (a hospital with no parent is its own
 * group); an empty doctor is "Unassigned doctor". `usage.cases` is the
 * drill-down list; patient name/reference are masked unless the caller holds
 * medical_cases.phi.view (#567).
 */
class MedicalCaseReportingService
{
    public const UNASSIGNED_DOCTOR = 'Unassigned doctor';

    /**
     * @param  int[]|null  $accessibleEntityIds  Issue #575: legal entities the
     *                                           caller may see (null = unscoped)
     * @param  array<string, int|null>  $filters  Issue #608: legal_entity_id,
     *                                            hospital_group_id, customer_id, doctor_contact_id, salesperson_id
     *                                            (case level) and product_id, category_id (usage rows only)
     */
    public function performance(
        Carbon $from,
        Carbon $to,
        string $dateField = 'case_date',
        ?array $accessibleEntityIds = null,
        array $filters = [],
        bool $canViewPhi = false,
    ): array {
        $dateField = in_array($dateField, ['case_date', 'procedure_date'], true) ? $dateField : 'case_date';

        $cases = MedicalCase::query()
            ->with([
                'customer:id,name,parent_id,legal_entity_id',
                'doctorContact:id,name',
                'items.product:id,name,sku,price,category_id',
                'items.product.category:id,name',
                'items.invoiceItem.invoice',
                'items.salesOrderItem.salesOrder',
            ])
            ->whereNotNull($dateField)
            ->whereBetween($dateField, [$from->toDateString(), $to->toDateString()])
            ->when($accessibleEntityIds !== null, fn ($query) => $query->accessibleBy($accessibleEntityIds))
            ->when(! empty($filters['customer_id']), fn ($query) => $query->where('customer_id', $filters['customer_id']))
            ->when(! empty($filters['doctor_contact_id']), fn ($query) => $query->where('doctor_contact_id', $filters['doctor_contact_id']))
            ->when(! empty($filters['salesperson_id']), fn ($query) => $query->whereHas(
                'customer',
                fn ($customer) => $customer->where('salesperson_id', $filters['salesperson_id'])
            ))
            ->when(! empty($filters['legal_entity_id']), fn ($query) => $query->where(function ($entity) use ($filters) {
                $entity->where('legal_entity_id', $filters['legal_entity_id'])
                    ->orWhere(fn ($legacy) => $legacy->whereNull('legal_entity_id')->whereHas(
                        'customer',
                        fn ($customer) => $customer->where('legal_entity_id', $filters['legal_entity_id'])
                    ));
            }))
            ->get();

        // Issue #608: hospital group = top-level parent of the case customer.
        $groups = $this->hospitalGroups($cases->pluck('customer')->filter()->all());
        if (! empty($filters['hospital_group_id'])) {
            $cases = $cases->filter(
                fn (MedicalCase $case) => (int) ($groups[$case->customer_id]['id'] ?? 0) === (int) $filters['hospital_group_id']
            )->values();
        }

        $entityNames = DB::table('legal_entities')
            ->whereIn('id', $cases->map(fn (MedicalCase $case) => $case->legal_entity_id ?? $case->customer?->legal_entity_id)->filter()->unique()->values())
            ->pluck('name', 'id');

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
        $usage = [];
        $usageCases = [];

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

            $entityId = $case->legal_entity_id ?? $case->customer?->legal_entity_id;
            $group = $groups[$case->customer_id] ?? ['id' => $case->customer_id, 'name' => $case->customer?->name];
            $caseInUsage = false;

            foreach ($case->items as $item) {
                $itemValue = 0.0;
                if ($item->is_foc) {
                    // Issue #574: FOC units are redeemed free of charge —
                    // reported as a count and at the product's list price.
                    $units = (float) $item->delivered_qty;
                    $focUnits += $units;
                    $focListValue += $units * (float) ($item->product?->price ?? 0);
                } else {
                    $itemValue = $this->itemInvoicedBaseValue($item, $creditedByLine);
                    $invoicedValue += $itemValue;
                }

                // Issue #608: product usage pivot row (finest grain).
                $usedUnits = (float) $item->delivered_qty;
                $productFiltered = (! empty($filters['product_id']) && (int) $item->product_id !== (int) $filters['product_id'])
                    || (! empty($filters['category_id']) && (int) ($item->product?->category_id ?? 0) !== (int) $filters['category_id']);
                if (! $productFiltered && (round($usedUnits, 4) > 0 || round($itemValue, 2) != 0.0)) {
                    $usageKey = implode('|', [$entityId ?? '', $group['id'] ?? '', $case->customer_id, $case->doctor_contact_id ?? '', $item->product_id ?? '']);
                    $usage[$usageKey] ??= [
                        'legal_entity_id' => $entityId,
                        'legal_entity_name' => $entityId ? ($entityNames[$entityId] ?? null) : null,
                        'hospital_group_id' => $group['id'],
                        'hospital_group_name' => $group['name'],
                        'hospital_id' => $case->customer_id,
                        'hospital_name' => $case->customer?->name,
                        'doctor_contact_id' => $case->doctor_contact_id,
                        'doctor_name' => $case->doctor_contact_id
                            ? ($case->doctorContact?->name ?? self::UNASSIGNED_DOCTOR)
                            : self::UNASSIGNED_DOCTOR,
                        'product_id' => $item->product_id,
                        'product_name' => $item->product?->name,
                        'short_description' => $item->product?->short_description,
                        'sku' => $item->product?->sku,
                        'category_id' => $item->product?->category_id,
                        'category_name' => $item->product?->category?->name,
                        'used_units' => 0.0,
                        'foc_units' => 0.0,
                        'paid_units' => 0.0,
                        'net_value' => 0.0,
                        'case_ids' => [],
                    ];
                    $usage[$usageKey]['used_units'] += $usedUnits;
                    $usage[$usageKey]['foc_units'] += $item->is_foc ? $usedUnits : 0.0;
                    $usage[$usageKey]['paid_units'] += $item->is_foc ? 0.0 : $usedUnits;
                    $usage[$usageKey]['net_value'] += $itemValue;
                    $usage[$usageKey]['case_ids'][$case->id] = $case->id;
                    $caseInUsage = true;
                }

                if (isset($usageCaseItemIds[$item->id])) {
                    $bonusCase = true;
                }

                $productKey = $item->product_id ?: 'unknown';
                if (! isset($productMix[$productKey])) {
                    $productMix[$productKey] = [
                        'product_id' => $item->product_id,
                        'product_name' => $item->product?->name,
                        'short_description' => $item->product?->short_description,
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

            if ($caseInUsage) {
                $usageCases[] = $this->drillDownCase($case, $canViewPhi);
            }

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
            // Issue #608: the Product usage pivot (finest grain rows; the
            // client nests and subtotals, unioning case_ids for distinct
            // case counts) and its drill-down case list.
            'usage' => [
                'rows' => array_values(array_map(function (array $row) {
                    $row['case_ids'] = array_values($row['case_ids']);
                    $row['case_count'] = count($row['case_ids']);
                    foreach (['used_units', 'foc_units', 'paid_units'] as $measure) {
                        $row[$measure] = round($row[$measure], 4);
                    }
                    $row['net_value'] = round($row['net_value'], 2);

                    return $row;
                }, $usage)),
                'cases' => $usageCases,
                'phi_masked' => ! $canViewPhi,
            ],
        ];
    }

    /**
     * Issue #608: top-level customers.parent_id ancestor per customer id.
     * A customer without a parent is its own group. Cycle-safe.
     *
     * @param  array<int, \App\Models\Customer>  $customers
     * @return array<int, array{id: int, name: string|null}>
     */
    private function hospitalGroups(array $customers): array
    {
        $known = [];
        foreach ($customers as $customer) {
            $known[$customer->id] = ['name' => $customer->name, 'parent_id' => $customer->parent_id];
        }

        // Load missing ancestors level by level (bounded depth).
        for ($depth = 0; $depth < 20; $depth++) {
            $missing = collect($known)->pluck('parent_id')->filter()
                ->reject(fn ($id) => isset($known[$id]))->unique()->values();
            if ($missing->isEmpty()) {
                break;
            }
            foreach (DB::table('customers')->whereIn('id', $missing)->get(['id', 'name', 'parent_id']) as $row) {
                $known[$row->id] = ['name' => $row->name, 'parent_id' => $row->parent_id];
            }
            // Parents that no longer exist end the chain.
            foreach ($missing as $id) {
                $known[$id] ??= null;
            }
        }

        $groups = [];
        foreach ($customers as $customer) {
            $topId = $customer->id;
            $visited = [$topId => true];
            while (($parentId = $known[$topId]['parent_id'] ?? null) && ($known[$parentId] ?? null) !== null && ! isset($visited[$parentId])) {
                $visited[$parentId] = true;
                $topId = $parentId;
            }
            $groups[$customer->id] = ['id' => (int) $topId, 'name' => $known[$topId]['name'] ?? null];
        }

        return $groups;
    }

    /**
     * Issue #608: one drill-down case; PHI masked unless the caller holds
     * medical_cases.phi.view (#567), using the platform FieldMaskingService.
     */
    private function drillDownCase(MedicalCase $case, bool $canViewPhi): array
    {
        $mask = fn (?string $value) => ($canViewPhi || $value === null || $value === '')
            ? $value
            : FieldMaskingService::maskDefault($value);

        return [
            'id' => $case->id,
            'case_no' => $case->case_no,
            'status' => $case->status,
            'case_date' => $case->case_date?->toDateString(),
            'procedure_date' => $case->procedure_date?->toDateString(),
            'hospital_id' => $case->customer_id,
            'hospital_name' => $case->customer?->name,
            'doctor_contact_id' => $case->doctor_contact_id,
            'doctor_name' => $case->doctor_contact_id
                ? ($case->doctorContact?->name ?? self::UNASSIGNED_DOCTOR)
                : self::UNASSIGNED_DOCTOR,
            'patient_name' => $mask($case->patient_name),
            'patient_reference' => $mask($case->patient_reference),
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
