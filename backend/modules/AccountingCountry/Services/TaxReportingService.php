<?php

namespace Modules\AccountingCountry\Services;

use App\Models\AccountMapping;
use Illuminate\Support\Facades\DB;

/**
 * MRD TAX-REQ-08 — tax reporting over the posted trail.
 *
 * Three accountant surfaces:
 *  - journal: every live tax transaction detail row, signed by document type,
 *    grouped by tax code (the drill-down every return figure must reach).
 *  - reconciliation: tax trail totals vs the GL tax control accounts, with
 *    named variance buckets (manual lines without tax identity, etc.) so the
 *    accountant can explain every cent between the subledger and the ledger.
 *  - filings: persisted return baselines vs periods that have tax activity
 *    but no recorded filing.
 *
 * All reading paths reuse TaxReturnAggregator's live-entries filter, so what
 * the journal says and what the return files can never diverge.
 */
class TaxReportingService
{
    public function __construct(private readonly TaxReturnAggregator $aggregator) {}

    /**
     * @return array{months: array<int,string>, rows: array<int, array<string, mixed>>, by_tax_code: array<int, array<string, mixed>>, totals: array{output_tax: float, input_tax: float, net: float}}
     */
    /**
     * #275: statutory filing calendar with due dates and status (filed /
     * overdue / open) for SG (quarterly GST F5) and MY (bi-monthly SST-02).
     *
     * @return array<int, array{period: string, months: array<int,string>, due_date: string, status: string}>
     */
    public function filingCalendar(string $country, int $year): array
    {
        $country = strtoupper($country);
        $windows = [];

        if ($country === 'SG') {
            $windows = [
                ['period' => "{$year}-Q1", 'months' => ["{$year}-01", "{$year}-02", "{$year}-03"], 'due' => date('Y-m-t', strtotime("{$year}-04-01"))],
                ['period' => "{$year}-Q2", 'months' => ["{$year}-04", "{$year}-05", "{$year}-06"], 'due' => date('Y-m-t', strtotime("{$year}-07-01"))],
                ['period' => "{$year}-Q3", 'months' => ["{$year}-07", "{$year}-08", "{$year}-09"], 'due' => date('Y-m-t', strtotime("{$year}-10-01"))],
                ['period' => "{$year}-Q4", 'months' => ["{$year}-10", "{$year}-11", "{$year}-12"], 'due' => date('Y-m-t', strtotime(($year + 1).'-01-01'))],
            ];
        } elseif ($country === 'MY') {
            $spans = [[1, 2], [3, 4], [5, 6], [7, 8], [9, 10], [11, 12]];

            foreach ($spans as [$start, $end]) {
                $months = [sprintf('%d-%02d', $year, $start), sprintf('%d-%02d', $year, $end)];
                $dueMonth = $end === 12 ? ($year + 1).'-01' : sprintf('%d-%02d', $year, $end + 1);
                $windows[] = [
                    'period' => $months[0].'~'.$months[1],
                    'months' => $months,
                    'due' => date('Y-m-t', strtotime($dueMonth.'-01')),
                ];
            }
        }

        $calendar = [];

        foreach ($windows as $window) {
            $filed = DB::table('statutory_return_filings')
                ->whereNull('superseded_at')
                ->where('country', $country)
                ->where(function ($q) use ($window) {
                    $q->whereIn('period', $window['months'])
                        ->orWhere('period', $window['period']);
                })
                ->exists();

            $duetoday = now()->toDateString();

            $calendar[] = [
                'period' => $window['period'],
                'months' => $window['months'],
                'due_date' => $window['due'],
                'status' => $filed ? 'filed' : ($window['due'] < $duetoday ? 'overdue' : 'open'),
            ];
        }

        return $calendar;
    }

    /**
     * #275: registration + turnover-threshold status. Thresholds: SG SGD
     * 1,000,000; MY MYR 500,000.
     *
     * @return array{registered: bool, ytd_taxable: float, threshold: float, threshold_exceeded: bool, country: string}
     */
    public function registrationStatus(string $country, ?int $legalEntityId = null): array
    {
        $country = strtoupper($country);
        $threshold = $country === 'SG' ? 1000000.0 : 500000.0;

        $registered = DB::table('tax_registrations')
            ->where('status', 'active')
            ->when($legalEntityId !== null, fn ($q) => $q->where('legal_entity_id', $legalEntityId))
            ->where('effective_from', '<=', now()->toDateString())
            ->where(function ($q) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString());
            })
            ->exists();

        $ytd = round((float) DB::table('tax_transaction_details as ttd')
            ->join('journal_entries as je', 'je.id', '=', 'ttd.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereRaw('ttd.reporting_period LIKE ?', [now()->format('Y').'-%'])
            ->whereRaw("upper(coalesce(ttd.jurisdiction, '')) = ?", [$country])
            ->sum('ttd.taxable_amount'), 2);

        return [
            'country' => $country,
            'registered' => $registered,
            'ytd_taxable' => $ytd,
            'threshold' => $threshold,
            'threshold_exceeded' => $ytd > $threshold,
        ];
    }

    /**
     * F18 (#281): deterministic exception catalogue for a period — variance,
     * uncoded control-account movement (reversal-excluded), unfiled activity
     * and trail rows missing a determination. Blocking exceptions feed the
     * readiness fact (ACC-011 close gate).
     *
     * @return array<int, array{code: string, severity: string, blocking: bool, count: int, message: string}>
     */
    public function exceptions(string $period, ?int $legalEntityId = null): array
    {
        $months = self::expandFilingPeriod($period) ?: [$period];
        $exceptions = [];

        $recon = $this->reconciliation($period);

        if (($recon['balanced'] ?? true) === false) {
            $exceptions[] = [
                'code' => 'RECONCILIATION_VARIANCE',
                'severity' => 'high',
                'blocking' => true,
                'count' => 1,
                'message' => 'Tax trail does not reconcile to GL for '.$period.'.',
            ];
        }

        $uncoded = self::uncodedTaxAccountMovement($months, $legalEntityId);

        if (($uncoded['count'] ?? 0) > 0) {
            $exceptions[] = [
                'code' => 'UNCODED_TAX_ACCOUNT_MOVEMENT',
                'severity' => 'medium',
                'blocking' => true,
                'count' => (int) $uncoded['count'],
                'message' => 'GL lines on tax control accounts without a tax_code.',
            ];
        }

        $activity = DB::table('tax_transaction_details as ttd')
            ->join('journal_entries as je', 'je.id', '=', 'ttd.journal_entry_id')
            ->whereIn('ttd.reporting_period', $months)
            ->where('je.status', 'posted')
            ->count();

        $filed = DB::table('statutory_return_filings')
            ->whereNull('superseded_at')
            ->whereIn('period', array_merge($months, [$period]))
            ->when($legalEntityId !== null, fn ($q) => $q->where('entity_key', $legalEntityId), fn ($q) => $q->where('entity_key', 0))
            ->exists();

        if ($activity > 0 && ! $filed) {
            $exceptions[] = [
                'code' => 'UNFILED_PERIOD',
                'severity' => 'high',
                'blocking' => true,
                'count' => $activity,
                'message' => 'Tax activity exists but the period has no current filing.',
            ];
        }

        $missingDetermination = DB::table('tax_transaction_details as ttd')
            ->join('journal_entries as je', 'je.id', '=', 'ttd.journal_entry_id')
            ->whereIn('ttd.reporting_period', $months)
            ->where('je.status', 'posted')
            ->whereNull('ttd.tax_determination_id')
            ->count();

        if ($missingDetermination > 0) {
            $exceptions[] = [
                'code' => 'MISSING_DETERMINATION',
                'severity' => 'medium',
                'blocking' => true,
                'count' => $missingDetermination,
                'message' => 'Trail rows without a determination reference (legacy/imported data).',
            ];
        }

        foreach (['SG', 'MY'] as $country) {
            $registration = $this->registrationStatus($country, $legalEntityId);

            if ($registration['threshold_exceeded'] && ! $registration['registered']) {
                $exceptions[] = [
                    'code' => 'REGISTRATION_REQUIRED',
                    'severity' => 'high',
                    'blocking' => true,
                    'count' => 1,
                    'message' => "Taxable turnover exceeds the {$country} registration threshold without an active registration.",
                ];
            }
        }

        return $exceptions;
    }

    /**
     * #278 F4: versioned tax-readiness fact for ACC-011 Financial Close
     * (locked / filed / unfiled + reconciliation state + deterministic
     * version hash). Financial Close consumes this instead of guessing.
     *
     * @return array<string, mixed>
     */
    public function readiness(string $period, ?int $legalEntityId = null): array
    {
        $locked = app(\App\Services\TaxPeriodClosingService::class)
            ->isPeriodLocked($period, (int) (tenant('id') ?? 0), $legalEntityId);

        $filing = DB::table('statutory_return_filings')
            ->whereNull('superseded_at')
            ->where('period', $period)
            ->where('entity_key', $legalEntityId ?? 0)
            ->orderByDesc('filing_version')
            ->first();

        $reconciliation = $this->reconciliation($period);

        $fact = [
            'period' => $period,
            'legal_entity_id' => $legalEntityId,
            'locked' => $locked,
            'filed' => $filing !== null,
            'unfiled' => $filing === null,
            'filing_state' => $filing->state ?? null,
            'filing_version' => $filing !== null ? (int) $filing->filing_version : null,
            'reconciliation' => $reconciliation,
        ];

        // F18 (#281): close-blocking exceptions gate ACC-011.
        $exceptions = $this->exceptions($period, $legalEntityId);
        $fact['blocking_exceptions'] = array_values(array_map(
            fn ($e) => $e['code'],
            array_filter($exceptions, fn ($e) => $e['blocking']),
        ));
        $fact['close_blocking'] = $fact['blocking_exceptions'] !== [];

        $fact['version'] = sha1(json_encode([
            $fact['period'], $fact['locked'], $fact['filed'], $fact['filing_state'],
            $fact['filing_version'], $reconciliation, $fact['blocking_exceptions'],
        ]));

        return $fact;
    }

    /**
     * F15 (#280): drill-down rows behind any return box/total. Shares the
     * aggregator's row path (sum equals the box value to the cent) and adds
     * journal entry + determination lineage.
     *
     * @return array{rows: array<int, array<string, mixed>>, totals: array{rows: int, taxable_amount: float, tax_amount: float}}
     */
    public function returnDetail(
        string $period,
        ?int $legalEntityId = null,
        ?string $direction = null,
        ?string $taxCode = null,
        ?string $documentType = null,
    ): array {
        $rows = $this->aggregator->drilldown($period, $legalEntityId, $direction, $taxCode, $documentType);

        return [
            'rows' => $rows,
            'totals' => [
                'rows' => count($rows),
                'taxable_amount' => round(array_sum(array_column($rows, 'taxable_amount')), 2),
                'tax_amount' => round(array_sum(array_column($rows, 'tax_amount')), 2),
            ],
        ];
    }

    public function journal(string $from, string $to, ?int $legalEntityId = null): array
    {
        $months = self::monthsBetween($from, $to);

        if ($months === []) {
            return ['months' => [], 'rows' => [], 'by_tax_code' => [], 'totals' => ['output_tax' => 0.0, 'input_tax' => 0.0, 'net' => 0.0]];
        }

        $aggregate = $this->aggregator->aggregate($months, $legalEntityId);

        $rows = [];

        foreach (['output', 'input'] as $direction) {
            foreach ($aggregate[$direction] as $row) {
                $rows[] = ['direction' => $direction] + $row;
            }
        }

        usort($rows, fn (array $a, array $b) => [(string) $b['reporting_period'], (int) $b['source_document_id']]
            <=> [(string) $a['reporting_period'], (int) $a['source_document_id']]);

        $byCode = [];

        foreach ($rows as $row) {
            $code = (string) $row['tax_code'];
            $byCode[$code] ??= ['tax_code' => $code, 'regime' => $row['tax_regime'], 'rate' => $row['tax_rate'], 'output_tax' => 0.0, 'input_tax' => 0.0, 'taxable_amount' => 0.0, 'rows' => 0];
            $byCode[$code][$row['direction'].'_tax'] = round($byCode[$code][$row['direction'].'_tax'] + (float) $row['tax_amount'], 2);
            $byCode[$code]['taxable_amount'] = round($byCode[$code]['taxable_amount'] + (float) $row['taxable_amount'], 2);
            $byCode[$code]['rows']++;
        }

        $outputTax = round(array_sum(array_map(fn ($r) => (float) $r['tax_amount'], $aggregate['output'])), 2);
        $inputTax = round(array_sum(array_map(fn ($r) => (float) $r['tax_amount'], $aggregate['input'])), 2);

        return [
            'months' => $months,
            'rows' => $rows,
            'by_tax_code' => array_values($byCode),
            'totals' => ['output_tax' => $outputTax, 'input_tax' => $inputTax, 'net' => round($outputTax - $inputTax, 2)],
        ];
    }

    /**
     * Trail vs GL reconciliation for a reporting period (month or quarter).
     *
     * Reconciled PER TAX CODE against live journal lines, not per account:
     * platform default mappings collapse output+input tax onto one control
     * account (known D8), so account-level nets are ambiguous by design.
     * GL lines on tax control accounts WITHOUT a tax_code (manual journals,
     * pre-Phase-0 postings) are surfaced as the unexplained movement.
     *
     * @return array<string, mixed>
     */
    public function reconciliation(string $period, ?int $legalEntityId = null): array
    {
        $months = TaxReturnAggregator::periodMonths($period) ?? [$period];

        $aggregate = $this->aggregator->aggregate($months, $legalEntityId);

        // Expected GL net per tax code from the trail: liability-positive
        // (output tax credits the account, input tax debits it).
        $expectedByCode = [];

        foreach (['output' => 1, 'input' => -1] as $direction => $sign) {
            foreach ($aggregate[$direction] as $row) {
                $code = (string) $row['tax_code'];
                $expectedByCode[$code] ??= ['tax_code' => $code, 'direction' => $direction, 'trail_tax' => 0.0, 'expected_gl_net' => 0.0];
                $expectedByCode[$code]['trail_tax'] = round($expectedByCode[$code]['trail_tax'] + (float) $row['tax_amount'], 2);
                $expectedByCode[$code]['expected_gl_net'] = round($expectedByCode[$code]['expected_gl_net'] + $sign * (float) $row['tax_amount'], 2);
            }
        }

        // MRD R4.5: withheld codes are excluded from return buckets but they
        // ARE expected on the ledger as payables (credit-positive).
        $withheld = $this->withheldByCode($months, $legalEntityId);

        foreach ($withheld as $code => $amount) {
            $expectedByCode[$code] = [
                'tax_code' => $code,
                'direction' => 'withholding',
                'trail_tax' => $amount,
                'expected_gl_net' => $amount,
            ];
        }

        // Actual live GL nets per tax code within the period.
        $glRows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->whereNotNull('jel.tax_code')
            ->where('je.status', 'posted')
            ->tap(fn ($liveQuery) => TaxReturnAggregator::applyLiveLifecycle($liveQuery))
            ->whereRaw("to_char(je.entry_date, 'YYYY-MM') IN (".implode(',', array_fill(0, count($months), '?')).')', $months)
            ->when($legalEntityId !== null, fn ($q) => $q->where('je.legal_entity_id', $legalEntityId))
            ->groupBy('jel.tax_code')
            ->get([
                'jel.tax_code',
                DB::raw('round(sum(jel.credit - jel.debit)::numeric, 2) as gl_net'),
            ]);

        $glByCode = [];
        foreach ($glRows as $row) {
            $glByCode[(string) $row->tax_code] = (float) $row->gl_net;
        }

        $byCode = [];
        $totalVariance = 0.0;

        foreach ($expectedByCode as $code => $data) {
            $glNet = round($glByCode[$code] ?? 0.0, 2);
            $variance = round($glNet - $data['expected_gl_net'], 2);
            $totalVariance = round($totalVariance + abs($variance), 2);

            $byCode[] = $data + ['gl_net' => $glNet, 'variance' => $variance];

            unset($glByCode[$code]);
        }

        // Coded GL lines with NO trail row in this period (orphan GL).
        foreach ($glByCode as $code => $glNet) {
            if (abs($glNet) < 0.01) {
                continue;
            }
            $byCode[] = [
                'tax_code' => $code,
                'direction' => 'unknown',
                'trail_tax' => 0.0,
                'expected_gl_net' => 0.0,
                'gl_net' => round($glNet, 2),
                'variance' => round($glNet, 2),
            ];
            $totalVariance = round($totalVariance + abs($glNet), 2);
        }

        $explanations = [];
        $uncoded = self::uncodedTaxAccountMovement($months, $legalEntityId);

        if ($uncoded['count'] > 0) {
            $explanations[] = [
                'kind' => 'gl_lines_without_tax_identity',
                'severity' => 'explain_variance',
                'message' => sprintf(
                    '%d untagged GL line(s) moved tax control accounts by %s net in this period without a tax_code — manual journals or legacy postings. These are NOT in any return trail and are the unexplained variance.',
                    $uncoded['count'],
                    number_format($uncoded['net'], 2),
                ),
            ];
            $totalVariance = round($totalVariance + abs($uncoded['net']), 2);
        }

        $totalTrailRows = DB::table('tax_transaction_details as ttd')
            ->join('journal_entries as je', 'je.id', '=', 'ttd.journal_entry_id')
            ->whereIn('ttd.reporting_period', $months)
            ->when($legalEntityId !== null, fn ($q) => $q->where('je.legal_entity_id', $legalEntityId))
            ->count();

        $liveRows = count($aggregate['output']) + count($aggregate['input']);

        if ($totalTrailRows > $liveRows) {
            $explanations[] = [
                'kind' => 'excluded_trail_rows',
                'severity' => 'informational',
                'message' => sprintf(
                    '%d trail row(s) in this period belong to reversed/voided entries or out-of-scope documents and are excluded from both sides consistently.',
                    $totalTrailRows - $liveRows,
                ),
            ];
        }

        return [
            'period' => $period,
            'months' => $months,
            'legal_entity_id' => $legalEntityId,
            'tax_period_locked' => DB::table('tax_period_locks')
                ->whereIn('period', $months)
                ->exists(),
            'by_tax_code' => array_values($byCode),
            'uncoded_gl_movement' => $uncoded,
            'total_abs_variance' => $totalVariance,
            'balanced' => $totalVariance < 0.01,
            'explanations' => $explanations,
        ];
    }

    /**
     * Lines hitting tax CONTROL accounts (per mapping keys, whichever accounts
     * they resolve to) with NO tax_code — the manual-drift bucket.
     */
    private static function uncodedTaxAccountMovement(array $months, ?int $legalEntityId): array
    {
        $accountIds = array_values(array_unique(array_filter([
            self::controlAccountId('sales_tax_payable'),
            self::controlAccountId('input_tax'),
        ])));

        if ($accountIds === []) {
            return ['count' => 0, 'net' => 0.0];
        }

        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->whereIn('jel.account_id', $accountIds)
            ->whereNull('jel.tax_code')
            ->where('je.status', 'posted')
            ->whereRaw("to_char(je.entry_date, 'YYYY-MM') IN (".implode(',', array_fill(0, count($months), '?')).')', $months)
            ->when($legalEntityId !== null, fn ($q) => $q->where('je.legal_entity_id', $legalEntityId))
            ->get(['jel.debit', 'jel.credit']);

        $net = 0.0;

        foreach ($rows as $row) {
            $net += (float) $row->credit - (float) $row->debit;
        }

        return ['count' => $rows->count(), 'net' => round($net, 2)];
    }

    /**
     * @return array{filings: array<int, array<string, mixed>>, open_periods: array<int, array<string, mixed>>}
     */
    public function filings(): array
    {
        // G-35: only current (non-superseded) filings track open/closed state.
        $filings = DB::table('statutory_return_filings')
            ->whereNull('superseded_at')
            ->orderByDesc('filed_at')
            ->get(['id', 'country', 'form', 'period', 'entity_key', 'filed_at', 'filed_by']);

        // #857 RP5 AC2: with exactly one legal entity, a legacy tenant-wide
        // filing (entity_key 0) and that entity are the same filer — otherwise
        // a pre-#736 filing never closes the entity's activity and the period
        // stays open forever.
        $entityIds = \App\Models\LegalEntity::query()->pluck('id');
        $singleEntityId = $entityIds->count() === 1 ? (int) $entityIds->first() : null;
        $canonicalKey = static function (int $key) use ($singleEntityId): int {
            return ($singleEntityId !== null && $key === 0) ? $singleEntityId : $key;
        };

        // G-29 (#274): a filing period covers every month it spans — a
        // quarterly filing (YYYY-Qn) must close its three monthly activity
        // periods, not just match an exact string. Entity key is the filing's
        // own legal-entity key (0 = tenant-wide), matched against the GL
        // entry's legal entity.
        $filedKeys = [];

        foreach ($filings as $filing) {
            foreach (self::expandFilingPeriod((string) $filing->period, (string) $filing->country) as $month) {
                $filedKeys[strtolower((string) $filing->country).'|'.$month.'|'.$canonicalKey((int) $filing->entity_key)] = true;
            }
        }

        // G-29: one grouped pass over activity (period × jurisdiction × entity)
        // replaces the per-period N+1 lookups. Filter mirrors
        // TaxReturnAggregator::availablePeriods.
        $activity = DB::table('tax_transaction_details as ttd')
            ->join('journal_entries as je', 'je.id', '=', 'ttd.journal_entry_id')
            ->where('je.status', 'posted')
            ->tap(fn ($liveQuery) => TaxReturnAggregator::applyLiveLifecycle($liveQuery))
            ->groupBy('ttd.reporting_period', 'ttd.jurisdiction', 'je.legal_entity_id')
            ->get([
                'ttd.reporting_period as period',
                'ttd.jurisdiction',
                'je.legal_entity_id as entity_id',
                DB::raw('count(*) as c'),
            ]);

        $open = [];

        foreach ($activity as $row) {
            $period = (string) ($row->period ?? '');

            if ($period === '') {
                continue;
            }

            // G-29: rows without a jurisdiction were silently skipped; surface
            // them so unfiled activity can never hide behind a null column.
            $country = strtoupper(trim((string) ($row->jurisdiction ?? '')));
            $country = $country === '' ? 'UNSPECIFIED' : $country;

            $entityKey = $canonicalKey((int) ($row->entity_id ?? 0));

            if (! isset($filedKeys[strtolower($country).'|'.$period.'|'.$entityKey])) {
                $open[] = [
                    'country' => $country,
                    'period' => $period,
                    'entity_key' => $entityKey,
                    'rows' => (int) $row->c,
                ];
            }
        }

        usort($open, fn ($a, $b) => [$b['period'], $a['country'], $a['entity_key']] <=> [$a['period'], $b['country'], $b['entity_key']]);

        return [
            'filings' => $filings->map(fn ($f) => [
                'id' => (int) $f->id,
                'country' => $f->country,
                'form' => $f->form,
                'period' => $f->period,
                'entity_key' => (int) $f->entity_key,
                'filed_at' => $f->filed_at,
                'filed_by' => $f->filed_by,
            ])->all(),
            'open_periods' => $open,
        ];
    }

    /**
     * G-29: the month keys a filing period covers.
     *
     * @return array<int, string>
     */
    /**
     * #741 T8: bimonthly SST-02 periods (YYYY-Bn) expand to their two months
     * via the shared period parser (registrant cycle start month).
     */
    public static function expandFilingPeriod(string $period, ?string $jurisdiction = null): array
    {
        if (preg_match('/^\d{4}-B[1-6]$/', $period) === 1) {
            return TaxReturnAggregator::periodMonths($period, $jurisdiction) ?? [];
        }

        if (preg_match('/^(\d{4})-Q([1-4])$/', $period, $m) === 1) {
            $start = ((int) $m[2] - 1) * 3 + 1;

            return [
                sprintf('%s-%02d', $m[1], $start),
                sprintf('%s-%02d', $m[1], $start + 1),
                sprintf('%s-%02d', $m[1], $start + 2),
            ];
        }

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1) {
            return [$period];
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    public static function monthsBetween(string $from, string $to): array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $from) !== 1 || preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $to) !== 1) {
            return [];
        }

        $months = [];
        $cursor = \DateTimeImmutable::createFromFormat('Y-m-d', $from.'-01');
        $end = \DateTimeImmutable::createFromFormat('Y-m-d', $to.'-01');

        if ($cursor === false || $end === false || $cursor > $end) {
            return [];
        }

        while ($cursor <= $end) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }

        // G-29 (#274): no silent truncation — callers passing ranges longer
        // than two years get every month (validation bounds the input regex).
        return $months;
    }

    private static function controlAccountId(string $mappingKey): ?int
    {
        $id = AccountMapping::query()
            ->where('mapping_key', $mappingKey)
            ->where('is_active', true)
            ->value('account_id');

        return $id ? (int) $id : null;
    }

    /**
     * Withheld amounts per tax code (trail rows whose rule type is
     * 'withholding') for the months, on live journal entries.
     *
     * @param  array<int, string>  $months
     * @return array<string, float>
     */
    public function withheldByCode(array $months, ?int $legalEntityId = null): array
    {
        $ruleIds = DB::table('taxes')->where('type', 'withholding')->pluck('id')->all();

        if ($ruleIds === []) {
            return [];
        }

        $rows = DB::table('tax_transaction_details as ttd')
            ->join('journal_entries as je', 'je.id', '=', 'ttd.journal_entry_id')
            ->whereIn('ttd.tax_rule_id', $ruleIds)
            ->whereIn('ttd.reporting_period', $months)
            ->where('je.status', 'posted')
            ->tap(fn ($liveQuery) => TaxReturnAggregator::applyLiveLifecycle($liveQuery))
            ->when($legalEntityId !== null, fn ($q) => $q->where('je.legal_entity_id', $legalEntityId))
            ->groupBy('ttd.tax_code')
            ->get(['ttd.tax_code', DB::raw('sum(ttd.tax_amount) as withheld')]);

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->tax_code] = round((float) $row->withheld, 2);
        }

        return $out;
    }

    /**
     * WHT payable view: withheld per month (trailing window the caller asks
     * for) — feeds the Tax Report tracker; remittance itself is the payment
     * leg booked separately.
     *
     * @return array{withheld_by_month: array<int, array{period:string, amount:float}>, total: float}
     */
    public function withholdingSummary(int $months = 12): array
    {
        $ruleIds = DB::table('taxes')->where('type', 'withholding')->pluck('id')->all();

        if ($ruleIds === []) {
            return [
                'withheld_by_month' => [], 'total' => 0.0, 'remitted_total' => 0.0,
                'open_total' => 0.0, 'payable_balance' => $this->whtPayableBalance(), 'variance' => 0.0,
            ];
        }

        $rows = DB::table('tax_transaction_details as ttd')
            ->join('journal_entries as je', 'je.id', '=', 'ttd.journal_entry_id')
            ->whereIn('ttd.tax_rule_id', $ruleIds)
            ->where('je.status', 'posted')
            ->tap(fn ($liveQuery) => TaxReturnAggregator::applyLiveLifecycle($liveQuery))
            ->groupBy('ttd.reporting_period')
            ->orderByDesc('ttd.reporting_period')
            ->limit($months)
            ->get(['ttd.reporting_period', DB::raw('sum(ttd.tax_amount) as withheld')]);

        $out = array_map(fn ($r) => ['period' => $r->reporting_period, 'amount' => round((float) $r->withheld, 2)], $rows->all());
        $total = round(array_sum(array_column($out, 'amount')), 2);
        $periods = array_column($out, 'period');
        $remitted = $periods === [] ? 0.0 : $this->remittedTotal($periods);
        $openAll = round($this->withheldAllTime() - $this->remittedTotal(null), 2);
        $balance = $this->whtPayableBalance();

        return [
            'withheld_by_month' => $out,
            'total' => $total,
            'remitted_total' => $remitted,
            'open_total' => round($total - $remitted, 2),
            'payable_balance' => $balance,
            'variance' => round($balance - $openAll, 2),
        ];
    }

    /**
     * #270: WHT schedule per authority + period (YYYY-MM or YYYY-Qn).
     * Rows: tax_code, withheld, remitted (allocated by withheld share), open.
     * Remittances are attributed to the period by payment date; the authority
     * dimension is descriptive on the remittance journal.
     *
     * @return array<int, array{tax_code: string, withheld: float, remitted: float, open: float}>
     */
    public function withholdingSchedule(string $authority, string $period): array
    {
        $authority = strtoupper($authority);
        $months = str_contains($period, 'Q') ? self::expandFilingPeriod($period) : [$period];

        if ($months === []) {
            return [];
        }

        $ruleIds = DB::table('taxes')->where('type', 'withholding')->pluck('id')->all();

        if ($ruleIds === []) {
            return [];
        }

        $rows = DB::table('tax_transaction_details as ttd')
            ->join('journal_entries as je', 'je.id', '=', 'ttd.journal_entry_id')
            ->leftJoin('taxes as t', 't.id', '=', 'ttd.tax_rule_id')
            ->whereIn('ttd.tax_rule_id', $ruleIds)
            ->whereIn('ttd.reporting_period', $months)
            ->where('je.status', 'posted')
            // Authority follows the withholding RULE's jurisdiction (the trail
            // row carries the document's jurisdiction, which may differ).
            ->whereRaw("upper(coalesce(t.jurisdiction, ttd.jurisdiction, '')) = ?", [$authority])
            ->tap(fn ($liveQuery) => TaxReturnAggregator::applyLiveLifecycle($liveQuery))
            ->groupBy('ttd.tax_code')
            ->get(['ttd.tax_code', DB::raw('sum(ttd.tax_amount) as withheld')]);

        $byCode = [];
        $withheldTotal = 0.0;

        foreach ($rows as $row) {
            $byCode[(string) $row->tax_code] = round((float) $row->withheld, 2);
            $withheldTotal = round($withheldTotal + (float) $row->withheld, 2);
        }

        $remittedTotal = $this->remittedTotal($months);

        $schedule = [];
        $running = 0.0;
        $codes = array_keys($byCode);
        $lastIndex = count($codes) - 1;

        foreach ($codes as $i => $code) {
            $remitted = $i === $lastIndex
                ? round($remittedTotal - $running, 2)
                : ($withheldTotal > 0 ? round($remittedTotal * ($byCode[$code] / $withheldTotal), 2) : 0.0);
            $running = round($running + $remitted, 2);

            $schedule[] = [
                'tax_code' => $code,
                'withheld' => $byCode[$code],
                'remitted' => $remitted,
                'open' => round($byCode[$code] - $remitted, 2),
            ];
        }

        return $schedule;
    }

    /**
     * Remittances booked for the months (or all time when null): DR legs on
     * the wht_payable control account from wht_remittance journals.
     *
     * @param  array<int, string>|null  $months
     */
    private function remittedTotal(?array $months = null): float
    {
        $whtAccountId = (int) (\App\Models\AccountMapping::where('mapping_key', 'wht_payable')->value('account_id') ?: 0);

        if (! $whtAccountId) {
            return 0.0;
        }

        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('jel.account_id', $whtAccountId)
            ->where('je.type', 'wht_remittance')
            ->where('je.status', 'posted');

        if ($months !== null && $months !== []) {
            $query->whereRaw("to_char(je.entry_date, 'YYYY-MM') IN (".implode(',', array_fill(0, count($months), '?')).')', $months);
        }

        return round((float) $query->sum('jel.debit'), 2);
    }

    private function withheldAllTime(): float
    {
        $ruleIds = DB::table('taxes')->where('type', 'withholding')->pluck('id')->all();

        if ($ruleIds === []) {
            return 0.0;
        }

        return round((float) DB::table('tax_transaction_details as ttd')
            ->join('journal_entries as je', 'je.id', '=', 'ttd.journal_entry_id')
            ->whereIn('ttd.tax_rule_id', $ruleIds)
            ->where('je.status', 'posted')
            ->tap(fn ($liveQuery) => TaxReturnAggregator::applyLiveLifecycle($liveQuery))
            ->sum('ttd.tax_amount'), 2);
    }

    private function whtPayableBalance(): float
    {
        $whtAccountId = (int) (\App\Models\AccountMapping::where('mapping_key', 'wht_payable')->value('account_id') ?: 0);

        if (! $whtAccountId) {
            return 0.0;
        }

        $row = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('jel.account_id', $whtAccountId)
            ->where('je.status', 'posted')
            ->tap(fn ($liveQuery) => TaxReturnAggregator::applyLiveLifecycle($liveQuery))
            ->selectRaw('coalesce(sum(jel.credit - jel.debit), 0) as balance')
            ->value('balance');

        return round((float) $row, 2);
    }
}
