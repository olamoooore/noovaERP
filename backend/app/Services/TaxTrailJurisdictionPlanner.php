<?php

namespace App\Services;

use App\Models\TaxRegistration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\AccountingCountry\Services\TaxReportingService;

/**
 * ADR-003B W6 step 2, step 3 of the hostile review — REPORT ONLY, for the
 * tax agent: the money impact of the buyer/vendor-country defects.
 *
 * POS / storefront / web-order sales and bills record the other party's
 * country as the jurisdiction of the tax trail row (reproduced in
 * TaxPosBuyerJurisdictionTest and TaxBillVendorJurisdictionTest), and the
 * tax return selects trail rows by jurisdiction. This report lists every
 * group of POSTED trail rows whose jurisdiction is not the recording
 * entity's own — the jurisdictions of its tax registrations, or the
 * tenant's home jurisdiction when it has none — by period, direction,
 * document type and currency, and says whether the period they belong to
 * was already FILED (a correction may need an amended return).
 *
 * It changes nothing and holds no personal data.
 */
class TaxTrailJurisdictionPlanner
{
    private const NOT_FILED_STATES = ['PREPARED', 'VOIDED'];

    public function __construct(private readonly TaxEngineService $engine) {}

    /**
     * @return array<string, mixed>
     */
    public function plan(string $tenantId): array
    {
        $tenantHome = strtoupper($this->engine->tenantJurisdiction());
        $entityHomes = $this->entityHomes();
        $filings = $this->filings();

        $groups = DB::table('tax_transaction_details as ttd')
            ->leftJoin('journal_entries as je', 'je.id', '=', 'ttd.journal_entry_id')
            ->selectRaw("ttd.reporting_period, je.legal_entity_id, upper(trim(coalesce(ttd.jurisdiction, ''))) as jurisdiction, ttd.direction, ttd.source_document_type, ttd.currency_code, count(*) as rows, sum(ttd.taxable_amount) as taxable_amount, sum(ttd.tax_amount) as tax_amount")
            ->groupBy('ttd.reporting_period', 'je.legal_entity_id', DB::raw("upper(trim(coalesce(ttd.jurisdiction, '')))"), 'ttd.direction', 'ttd.source_document_type', 'ttd.currency_code')
            ->orderBy('ttd.reporting_period')
            ->get();

        $outside = [];
        $totals = ['rows_checked' => 0, 'rows_outside_home' => 0];
        $byDirectionCurrency = [];
        $recorded = [];
        $periods = [];
        $filedPeriods = [];

        foreach ($groups as $group) {
            $totals['rows_checked'] += (int) $group->rows;
            $entityId = $group->legal_entity_id !== null ? (int) $group->legal_entity_id : null;
            $homes = $entityId !== null && isset($entityHomes[$entityId]) ? $entityHomes[$entityId] : [$tenantHome];

            if (in_array($group->jurisdiction, $homes, true)) {
                continue;
            }

            $category = match (true) {
                $group->jurisdiction === '' => 'missing',
                preg_match('/^[A-Z]{2}$/', $group->jurisdiction) !== 1 => 'unrecognised_text',
                default => 'foreign_code',
            };

            $filedBy = $this->filedBy($filings, $homes, $entityId, (string) $group->reporting_period);
            // A return only prepared (or voided) has not gone to the authority.
            $isFiled = collect($filedBy)->contains(fn ($f) => ! in_array($f['state'], self::NOT_FILED_STATES, true));

            $outside[] = [
                'reporting_period' => $group->reporting_period,
                'legal_entity_id' => $entityId,
                'home_jurisdictions' => $homes,
                'recorded_jurisdiction' => $group->jurisdiction === '' ? null : $group->jurisdiction,
                'category' => $category,
                'direction' => $group->direction,
                'source_document_type' => $group->source_document_type,
                'currency_code' => $group->currency_code,
                'rows' => (int) $group->rows,
                'taxable_amount' => round((float) $group->taxable_amount, 2),
                'tax_amount' => round((float) $group->tax_amount, 2),
                'period_filed' => $isFiled,
                'filings' => $filedBy,
            ];

            $totals['rows_outside_home'] += (int) $group->rows;
            $key = ($group->direction ?? 'unknown').'|'.($group->currency_code ?? 'unknown');
            $byDirectionCurrency[$key] = round(($byDirectionCurrency[$key] ?? 0) + (float) $group->tax_amount, 2);
            $recorded[$group->jurisdiction === '' ? '(missing)' : $group->jurisdiction] = true;
            $periods[$group->reporting_period] = true;
            if ($isFiled) {
                $filedPeriods[$group->reporting_period] = true;
            }
        }

        ksort($periods);
        ksort($filedPeriods);

        return [
            'tenant_id' => $tenantId,
            'generated_at' => now()->toIso8601String(),
            'mode' => 'report_only',
            'tenant_home_jurisdiction' => $tenantHome,
            'summary' => $totals + [
                // "direction|currency" => tax amount recorded outside home.
                'tax_outside_home' => $byDirectionCurrency,
                'recorded_jurisdictions' => array_keys($recorded),
                'periods_affected' => array_keys($periods),
                'filed_periods_affected' => array_keys($filedPeriods),
            ],
            'groups' => $outside,
        ];
    }

    /**
     * Legal entity id → every jurisdiction it is (or was) registered in.
     *
     * @return array<int, list<string>>
     */
    private function entityHomes(): array
    {
        if (! Schema::hasTable('tax_registrations') || ! Schema::hasColumn('tax_registrations', 'jurisdiction')) {
            return [];
        }

        return TaxRegistration::query()
            ->whereNotNull('jurisdiction')
            ->where('jurisdiction', '!=', '')
            ->get(['legal_entity_id', 'jurisdiction'])
            ->groupBy('legal_entity_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => strtoupper(trim($r->jurisdiction)))->unique()->values()->all())
            ->all();
    }

    /** @return list<object> */
    private function filings(): array
    {
        if (! Schema::hasTable('statutory_return_filings')) {
            return [];
        }

        $columns = ['id', 'country', 'form', 'period', 'entity_key', 'filed_at'];
        if (Schema::hasColumn('statutory_return_filings', 'state')) {
            $columns[] = 'state';
        }

        return DB::table('statutory_return_filings')->get($columns)->all();
    }

    /**
     * Returns of the entity's own country whose period covers this month
     * (entity_key 0 = a tenant-wide return), in any state.
     *
     * @param  list<object>  $filings
     * @param  list<string>  $homes
     * @return list<array<string, mixed>>
     */
    private function filedBy(array $filings, array $homes, ?int $entityId, string $month): array
    {
        $found = [];
        foreach ($filings as $filing) {
            $country = strtoupper((string) $filing->country);
            if (! in_array($country, $homes, true)) {
                continue;
            }
            if ((int) $filing->entity_key !== 0 && (int) $filing->entity_key !== (int) $entityId) {
                continue;
            }
            $months = TaxReportingService::expandFilingPeriod((string) $filing->period, $country);
            if ($months === [] ? $filing->period === $month : in_array($month, $months, true)) {
                $found[] = [
                    'id' => $filing->id,
                    'country' => $country,
                    'form' => $filing->form,
                    'period' => $filing->period,
                    'state' => $filing->state ?? 'FILED',
                ];
            }
        }

        return $found;
    }
}
