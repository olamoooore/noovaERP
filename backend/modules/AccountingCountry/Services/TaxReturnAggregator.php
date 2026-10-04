<?php

namespace Modules\AccountingCountry\Services;

use Illuminate\Support\Facades\DB;

/**
 * Issue #93: shared aggregation of the tax audit data written by
 * PostingEngine::writeTaxTransactionDetails() into the direction and supply
 * category the statutory return generators need.
 *
 * Direction: sales-side documents produce output tax, purchase-side documents
 * produce input tax. Credit notes carry negative amounts (they reverse tax).
 * Category: zero/exempt come from the persisted tax treatment; a positive rate
 * is standard-rated, otherwise out of scope.
 */
class TaxReturnAggregator
{
    private const SALES_DOCUMENTS = [
        'invoice', 'sales_invoice', 'credit_note', 'pos_order', 'web_order', 'storefront_order',
    ];

    private const PURCHASE_DOCUMENTS = [
        'bill', 'purchase_invoice', 'vendor_credit_note', 'vendor_debit_note', 'goods_receipt', 'expense',
    ];

    private const NEGATIVE_DOCUMENTS = ['credit_note', 'vendor_credit_note', 'vendor_debit_note'];

    /**
     * @param  string|array<int, string>  $period  One reporting period (YYYY-MM)
     *                                             or several (quarterly returns).
     * @param  int|null  $legalEntityId  Issue #93: scope the return to one
     *                                   legal entity so a multi-entity tenant
     *                                   does not file one combined return.
     * @return array{output: array<int, array<string, mixed>>, input: array<int, array<string, mixed>>}
     */
    /**
     * #742 T9 / #860 RP6: the ONE lifecycle rule every tax report shares —
     * originals are kept (including `reversed`; the reversal engine writes
     * signed offsetting rows), and only voided/superseded documents leave the
     * numbers. Exposed statically so TaxReportingService's GL queries use the
     * exact same scope as this aggregator instead of their own.
     */
    public const EXCLUDED_LIFECYCLE = ['voided', 'superseded'];

    public static function applyLiveLifecycle($query, string $alias = 'je'): void
    {
        $query->where(function ($q) use ($alias) {
            $q->whereNull("{$alias}.lifecycle_status")
                ->orWhereNotIn("{$alias}.lifecycle_status", self::EXCLUDED_LIFECYCLE);
        });
    }

    /**
     * #742 T9: the ONE lifecycle filter shared by the return aggregate and
     * the period list. Voided/superseded documents leave the return (they
     * have no offsetting signed trail), reversed originals stay because the
     * reversal engine writes signed rows — both queries must agree.
     */
    private function liveLifecycleFilter(): \Closure
    {
        return function ($query) {
            self::applyLiveLifecycle($query);
        };
    }

    public function aggregate(string|array $period, ?int $legalEntityId = null, ?string $jurisdiction = null): array
    {
        $keys = [
            'reporting_period', 'source_document_type', 'source_document_id', 'tax_code',
            'tax_rule_id', 'tax_rate', 'tax_treatment', 'tax_regime', 'taxable_amount',
            'tax_amount', 'category',
        ];

        $result = ['output' => [], 'input' => [], 'unassigned' => ['rows' => 0]];

        foreach ($this->normalizedRows($period, $legalEntityId, $jurisdiction) as $row) {
            $result[$row['direction']][] = array_intersect_key($row, array_flip($keys));
        }

        // #736 T3: rows with a NULL jurisdiction are never silently folded
        // into a country return — they are reported as unassigned.
        if ($jurisdiction !== null) {
            $result['unassigned']['rows'] = $this->countUnassignedRows($period, $legalEntityId, $jurisdiction);
        }

        return $result;
    }

    /**
     * #736 T3: live trail rows in the window carrying no jurisdiction.
     */
    private function countUnassignedRows(string|array $period, ?int $legalEntityId, ?string $jurisdiction = null): int
    {
        $periods = is_array($period) ? array_values($period) : [$period];
        $periods = array_merge(...array_map(
            fn (string $p) => self::periodMonths($p, $jurisdiction, $legalEntityId) ?? [$p],
            $periods
        ));

        return DB::table('tax_transaction_details as ttd')
            ->join('journal_entries as je', 'je.id', '=', 'ttd.journal_entry_id')
            ->where('je.status', 'posted')
            ->where($this->liveLifecycleFilter())
            ->whereNull('ttd.jurisdiction')
            ->whereIn('ttd.reporting_period', $periods)
            ->when($legalEntityId !== null, fn ($query) => $query->where('je.legal_entity_id', $legalEntityId))
            ->count();
    }

    /**
     * F15 (#280): the drill-down shares the EXACT row path with aggregate(),
     * so the sum of drill-down rows equals the box value to the cent.
     *
     * @return array<int, array<string, mixed>>
     */
    public function drilldown(
        string|array $period,
        ?int $legalEntityId = null,
        ?string $direction = null,
        ?string $taxCode = null,
        ?string $documentType = null,
    ): array {
        $rows = $this->normalizedRows($period, $legalEntityId);

        return array_values(array_filter($rows, function (array $row) use ($direction, $taxCode, $documentType) {
            if ($direction !== null && $row['direction'] !== strtolower($direction)) {
                return false;
            }

            if ($taxCode !== null && (string) $row['tax_code'] !== $taxCode) {
                return false;
            }

            if ($documentType !== null && (string) $row['source_document_type'] !== $documentType) {
                return false;
            }

            return true;
        }));
    }

    /**
     * The single shared live-entry row path: expand periods, filter live
     * entries, drop withholding rows, resolve direction/sign/category once.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizedRows(string|array $period, ?int $legalEntityId, ?string $jurisdiction = null): array
    {
        $periods = is_array($period) ? array_values($period) : [$period];

        // Expand compound periods (YYYY-Qn, YYYY-Bn) into their months so a
        // quarterly/bimonthly return aggregates exactly those months.
        $periods = array_merge(...array_map(
            fn (string $p) => self::periodMonths($p, $jurisdiction, $legalEntityId) ?? [$p],
            $periods
        ));

        // MRD gap review G-33/G-34: reversals are corrections, not erasures.
        // The reversal engine writes SIGNED trail rows (its own reporting
        // period), so originals always count; only voided/superseded documents
        // (which have no offsetting trail) leave the return.
        $rows = DB::table('tax_transaction_details as ttd')
            ->join('journal_entries as je', 'je.id', '=', 'ttd.journal_entry_id')
            ->where('je.status', 'posted')
            ->where($this->liveLifecycleFilter())
            ->whereIn('ttd.reporting_period', $periods)
            ->when(
                $jurisdiction !== null,
                fn ($query) => $query->whereRaw('upper(ttd.jurisdiction) = ?', [strtoupper($jurisdiction)])
            )
            ->when(
                $legalEntityId !== null,
                fn ($query) => $query->where('je.legal_entity_id', $legalEntityId)
            )
            ->orderBy('ttd.id')
            ->get(['ttd.*']);

        // MRD R4.5: withholding trail rows belong to WHT reporting, never
        // to GST/SST supply or claim buckets.
        $withholdingRuleIds = DB::table('taxes')->where('type', 'withholding')->pluck('id')->flip();

        $normalized = [];

        foreach ($rows as $row) {
            if ($row->tax_rule_id !== null && isset($withholdingRuleIds[(int) $row->tax_rule_id])) {
                continue;
            }

            // MRD R4.3: an explicit row direction (reverse-charge pairs) wins
            // over the document-type default.
            $explicit = strtolower((string) ($row->direction ?? ''));

            // G-32: zero-rated/exempt supply rows (direction 'supply') are
            // output-side supplies with no tax amount.
            $direction = $explicit === 'supply'
                ? 'output'
                : ($explicit !== ''
                    ? (in_array($explicit, ['output', 'input'], true) ? $explicit : null)
                    : $this->direction((string) $row->source_document_type));

            if ($direction === null) {
                continue;
            }

            $sign = in_array((string) $row->source_document_type, self::NEGATIVE_DOCUMENTS, true) ? -1 : 1;

            $normalized[] = [
                'id' => (int) $row->id,
                'reporting_period' => $row->reporting_period,
                'source_document_type' => $row->source_document_type,
                'source_document_id' => $row->source_document_id,
                'tax_code' => $row->tax_code,
                'tax_rule_id' => $row->tax_rule_id !== null ? (int) $row->tax_rule_id : null,
                'tax_rate' => $row->tax_rate !== null ? (float) $row->tax_rate : null,
                'tax_treatment' => $row->tax_treatment,
                'tax_regime' => $row->tax_regime,
                'taxable_amount' => round($sign * (float) $row->taxable_amount, 2),
                'tax_amount' => round($sign * (float) $row->tax_amount, 2),
                'category' => $this->category($row),
                'direction' => $direction,
                'stored_direction' => $row->direction,
                'journal_entry_id' => (int) $row->journal_entry_id,
                'tax_determination_id' => $row->tax_determination_id,
                'result_version' => $row->result_version !== null ? (int) $row->result_version : null,
                'currency_code' => $row->currency_code ?? null,
                'exchange_rate' => isset($row->exchange_rate) && $row->exchange_rate !== null ? (float) $row->exchange_rate : null,
            ];
        }

        return $normalized;
    }

    /**
     * Resolve a period string into the YYYY-MM months it covers.
     *
     * @return array<int, string>|null null when the period is malformed.
     */
    /**
     * #741 T8: YYYY-MM (month), YYYY-Qn (quarter), or YYYY-Bn (bimonthly
     * block, 1-6). Bimonthly blocks start at the registrant's cycle start
     * month (tax_registrations.filing_start_month, default January).
     */
    public static function periodMonths(string $period, ?string $jurisdiction = null, ?int $legalEntityId = null): ?array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1) {
            return [$period];
        }

        if (preg_match('/^(\d{4})-Q([1-4])$/', $period, $m) === 1) {
            $year = (int) $m[1];
            $startMonth = ((int) $m[2] - 1) * 3 + 1;

            return array_map(
                fn (int $offset) => sprintf('%04d-%02d', $year, $startMonth + $offset),
                [0, 1, 2]
            );
        }

        if (preg_match('/^(\d{4})-B([1-6])$/', $period, $m) === 1) {
            $year = (int) $m[1];
            $block = (int) $m[2];
            $cycleStart = self::registrationStartMonth($jurisdiction, $legalEntityId);

            // Offset of the block within the registrant's 12-month cycle;
            // wraps across the calendar year (a July cycle's B4 is Jan-Feb).
            $offset = ($cycleStart - 1) + ($block - 1) * 2;

            $months = [];
            foreach ([0, 1] as $k) {
                $position = $offset + $k;
                $months[] = sprintf(
                    '%04d-%02d',
                    $year + intdiv($position, 12),
                    ($position % 12) + 1,
                );
            }

            return $months;
        }

        return null;
    }

    /**
     * #741 T8: the cycle start month of the active tax registration (latest
     * effective_from wins). A null jurisdiction (e.g. drilldown callers with
     * no country context) accepts any active registration — tenants run one
     * filing cycle. No registration means the default January cycle; values
     * outside 1-12 fall back to 1.
     */
    public static function registrationStartMonth(?string $jurisdiction, ?int $legalEntityId = null): int
    {
        $month = DB::table('tax_registrations')
            ->when($jurisdiction !== null, fn ($q) => $q->where('jurisdiction', strtoupper($jurisdiction)))
            ->where('status', 'active')
            ->when($legalEntityId !== null, fn ($q) => $q->where('legal_entity_id', $legalEntityId))
            ->orderByDesc('effective_from')
            ->value('filing_start_month');

        $month = (int) ($month ?? 1);

        return $month >= 1 && $month <= 12 ? $month : 1;
    }

    /**
     * #750 TU4: the periods the registration actually files, derived from its
     * filing_frequency and cycle start month (#741) — not free-typed. Newest
     * first, each with its filing status.
     *
     * @return array<int, array{period:string,label:string,status:string,filing_version:?int}>
     */
    public function registrationPeriods(string $country, ?int $legalEntityId = null, int $count = 8): array
    {
        $country = strtoupper($country);

        $frequency = (string) (DB::table('tax_registrations')
            ->where('jurisdiction', $country)
            ->where('status', 'active')
            ->when($legalEntityId !== null, fn ($q) => $q->where('legal_entity_id', $legalEntityId))
            ->orderByDesc('effective_from')
            ->value('filing_frequency') ?: 'monthly');

        if (! in_array($frequency, ['monthly', 'quarterly', 'bimonthly'], true)) {
            $frequency = 'monthly';
        }

        $cycleStart = self::registrationStartMonth($country, $legalEntityId);
        $cursor = now()->startOfMonth();

        // Align a two-monthly cursor to the registrant's block start.
        if ($frequency === 'bimonthly') {
            $position = ((int) $cursor->month - $cycleStart + 12) % 12;
            $cursor = $cursor->copy()->subMonths($position % 2);
        }

        $periods = [];
        for ($i = 0; $i < $count; $i++) {
            $periods[$this->encodePeriod($frequency, $cursor, $cycleStart)] = $cursor->copy();

            $cursor = match ($frequency) {
                'quarterly' => $cursor->copy()->subMonths(3),
                'bimonthly' => $cursor->copy()->subMonths(2),
                default => $cursor->copy()->subMonthNoOverflow(),
            };
        }

        $entityKey = \App\Models\StatutoryReturnFiling::entityKey($legalEntityId);
        $filings = DB::table('statutory_return_filings')
            ->where('country', $country)
            ->where('entity_key', $entityKey)
            ->whereNull('superseded_at')
            ->whereIn('period', array_keys($periods))
            ->get(['period', 'filing_version'])
            ->keyBy('period');

        $out = [];
        foreach ($periods as $period => $start) {
            $filing = $filings->get($period);

            $out[] = [
                'period' => $period,
                'label' => $this->periodLabel($frequency, $start, $cycleStart),
                'status' => $filing === null ? 'Open' : ((int) $filing->filing_version > 1 ? 'Amended' : 'Filed'),
                'filing_version' => $filing?->filing_version,
            ];
        }

        return $out;
    }

    private function encodePeriod(string $frequency, \Carbon\Carbon $start, int $cycleStart): string
    {
        if ($frequency === 'quarterly') {
            return sprintf('%04d-Q%d', $start->year, intdiv($start->month - 1, 3) + 1);
        }

        if ($frequency === 'bimonthly') {
            $position = ((int) $start->month - $cycleStart + 12) % 12;
            $block = intdiv($position, 2) + 1;
            // periodMonths() decodes a block against the year its cycle began.
            $cycleYear = $start->month >= $cycleStart ? $start->year : $start->year - 1;

            return sprintf('%04d-B%d', $cycleYear, $block);
        }

        return $start->format('Y-m');
    }

    private function periodLabel(string $frequency, \Carbon\Carbon $start, int $cycleStart): string
    {
        if ($frequency === 'quarterly') {
            return 'Q'.(intdiv($start->month - 1, 3) + 1).' '.$start->year;
        }

        if ($frequency === 'bimonthly') {
            return $start->format('M').'–'.$start->copy()->addMonth()->format('M Y');
        }

        return $start->format('M Y');
    }

    /**
     * Periods that actually contain live tax activity, newest first. Returns
     * reporting periods from tax_transaction_details joined to the same live
     * journal-entry filter used by aggregate(), with signed output/input tax
     * totals per period so the UI can show filing-relevant data instead of
     * empty calendars.
     *
     * @return array<int, array{period: string, rows: int, output_tax: float, input_tax: float}>
     */
    public function availablePeriods(?int $legalEntityId = null): array
    {
        $rows = DB::table('tax_transaction_details as ttd')
            ->join('journal_entries as je', 'je.id', '=', 'ttd.journal_entry_id')
            ->where('je.status', 'posted')
            ->where($this->liveLifecycleFilter())
            ->when(
                $legalEntityId !== null,
                fn ($query) => $query->where('je.legal_entity_id', $legalEntityId)
            )
            ->groupBy('ttd.reporting_period', 'ttd.source_document_type')
            ->get([
                'ttd.reporting_period as period',
                'ttd.source_document_type',
                DB::raw('count(*) as rows'),
                DB::raw('sum(ttd.tax_amount) as tax_total'),
            ]);

        $periods = [];

        foreach ($rows as $row) {
            $period = (string) $row->period;
            $periods[$period] ??= ['period' => $period, 'rows' => 0, 'output_tax' => 0.0, 'input_tax' => 0.0];

            $direction = $this->direction((string) $row->source_document_type);
            if ($direction === null) {
                $periods[$period]['rows'] += (int) $row->rows;

                continue;
            }

            $sign = in_array((string) $row->source_document_type, self::NEGATIVE_DOCUMENTS, true) ? -1 : 1;

            $periods[$period]['rows'] += (int) $row->rows;
            $periods[$period][$direction.'_tax'] = round(
                $periods[$period][$direction.'_tax'] + $sign * (float) $row->tax_total,
                2,
            );
        }

        usort($periods, fn ($a, $b) => strcmp($b['period'], $a['period']));

        return array_values($periods);
    }

    /**
     * F15 (#280): the auditable direction registry — a document type not in
     * here cannot silently vanish from returns: it is skipped AND logged, and
     * TaxReturnDrilldownTest asserts every engine posting type is registered.
     *
     * @return array<string, string> document_type => output|input
     */
    public static function directionRegistry(): array
    {
        $registry = [];

        foreach (self::SALES_DOCUMENTS as $type) {
            $registry[$type] = 'output';
        }

        foreach (self::PURCHASE_DOCUMENTS as $type) {
            $registry[$type] = 'input';
        }

        return $registry;
    }

    private function direction(string $documentType): ?string
    {
        $registry = self::directionRegistry();

        if (isset($registry[$documentType])) {
            return $registry[$documentType];
        }

        \Illuminate\Support\Facades\Log::warning('tax.return.unregistered_document_type', [
            'document_type' => $documentType,
        ]);

        return null;
    }

    private function category(object $row): string
    {
        $treatment = strtolower((string) ($row->tax_treatment ?? ''));

        if (str_contains($treatment, 'zero')) {
            return 'zero_rated';
        }

        if (str_contains($treatment, 'exempt')) {
            return 'exempt';
        }

        if ((float) ($row->tax_rate ?? 0) > 0) {
            return 'standard_rated';
        }

        return 'out_of_scope';
    }
}
