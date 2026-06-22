<?php

namespace Modules\AccountingCountry\Singapore\Services;

use App\Accounting\Contracts\StatutoryReportExporterContract;
use App\Models\ChartOfAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class XBRLReportExporter implements StatutoryReportExporterContract
{
    private array $lastXbrlValidation = [
        'validated' => false,
        'valid' => null,
        'errors' => [],
        'warnings' => [],
        'entry_point_xsd' => null,
        'schema_ref_href' => null,
    ];

    public function __construct(
        private ?SFRSTaxonomyProfile $taxonomyProfile = null
    ) {
        $this->taxonomyProfile ??= new SFRSTaxonomyProfile();
    }

    public function exportTrialBalance(\DateTime $asOfDate, array $options = []): array
    {
        $balances = $this->buildAsOfBalances(Carbon::instance($asOfDate));
        $trialBalance = [];
        $totalDebits = 0.0;
        $totalCredits = 0.0;

        foreach ($balances as $balance) {
            if (abs($balance['debit']) < 0.01 && abs($balance['credit']) < 0.01) {
                continue;
            }

            $trialBalance[] = [
                'account_id' => $balance['account_id'],
                'account_code' => $balance['account_code'],
                'account_name' => $balance['account_name'],
                'account_type' => $balance['account_type'],
                'account_subtype' => $balance['account_subtype'],
                'taxonomy_element' => $balance['mapping']['element'] ?? null,
                'taxonomy_label' => $balance['mapping']['label'] ?? null,
                'statement' => $balance['mapping']['statement'] ?? null,
                'section' => $balance['mapping']['section'] ?? null,
                'debit' => $balance['debit'],
                'credit' => $balance['credit'],
                'net_balance' => $balance['net_balance'],
            ];
            $totalDebits += $balance['debit'];
            $totalCredits += $balance['credit'];
        }

        return [
            'type' => 'trial_balance',
            'taxonomy_version' => $this->taxonomyProfile->taxonomyVersion(),
            'as_of_date' => $asOfDate->format('Y-m-d'),
            'accounts' => $trialBalance,
            'totals' => [
                'total_debits' => round($totalDebits, 2),
                'total_credits' => round($totalCredits, 2),
                'balanced' => abs($totalDebits - $totalCredits) < 0.01,
            ],
            'mapping_summary' => $this->summarizeMappings($trialBalance),
        ];
    }

    public function exportIncomeStatement(
        \DateTime $startDate,
        \DateTime $endDate,
        array $options = []
    ): array {
        $lines = $this->buildPeriodMovements(Carbon::instance($startDate), Carbon::instance($endDate), ['revenue', 'expense']);
        $grouped = $this->groupLinesByTaxonomySection($lines, 'income_statement');

        $revenue = $grouped['revenue']['total'] ?? 0.0;
        $costOfGoods = $grouped['cost_of_goods_sold']['total'] ?? 0.0;
        $operatingExpenses = $grouped['operating_expenses']['total'] ?? 0.0;
        $otherIncome = $grouped['other_income']['total'] ?? 0.0;
        $otherExpenses = $grouped['other_expenses']['total'] ?? 0.0;
        $grossProfit = $revenue - $costOfGoods;
        $operatingIncome = $grossProfit - $operatingExpenses;
        $netIncome = $operatingIncome + $otherIncome - $otherExpenses;

        return [
            'type' => 'income_statement',
            'taxonomy_version' => $this->taxonomyProfile->taxonomyVersion(),
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'revenue' => $grouped['revenue'] ?? ['items' => [], 'total' => 0.0],
            'cost_of_goods_sold' => $grouped['cost_of_goods_sold'] ?? ['items' => [], 'total' => 0.0],
            'gross_profit' => round($grossProfit, 2),
            'operating_expenses' => $grouped['operating_expenses'] ?? ['items' => [], 'total' => 0.0],
            'operating_income' => round($operatingIncome, 2),
            'other_income' => $grouped['other_income'] ?? ['items' => [], 'total' => 0.0],
            'other_expenses' => $grouped['other_expenses'] ?? ['items' => [], 'total' => 0.0],
            'net_income' => round($netIncome, 2),
            'mapping_summary' => $this->summarizeMappings($lines),
        ];
    }

    public function exportBalanceSheet(\DateTime $asOfDate, array $options = []): array
    {
        $balances = $this->buildAsOfBalances(Carbon::instance($asOfDate));
        $grouped = $this->groupLinesByTaxonomySection($balances, 'balance_sheet');

        $currentAssets = $grouped['assets.current_assets'] ?? ['items' => [], 'total' => 0.0];
        $nonCurrentAssets = $grouped['assets.non_current_assets'] ?? ['items' => [], 'total' => 0.0];
        $currentLiabilities = $grouped['liabilities.current_liabilities'] ?? ['items' => [], 'total' => 0.0];
        $nonCurrentLiabilities = $grouped['liabilities.non_current_liabilities'] ?? ['items' => [], 'total' => 0.0];
        $capitalAndReserves = $grouped['equity.capital_and_reserves'] ?? ['items' => [], 'total' => 0.0];

        $retainedEarnings = $this->calculateRetainedEarnings(Carbon::instance($asOfDate));
        $totalAssets = $currentAssets['total'] + $nonCurrentAssets['total'];
        $totalLiabilities = $currentLiabilities['total'] + $nonCurrentLiabilities['total'];
        $totalEquity = $capitalAndReserves['total'] + $retainedEarnings;

        return [
            'type' => 'balance_sheet',
            'taxonomy_version' => $this->taxonomyProfile->taxonomyVersion(),
            'as_of_date' => $asOfDate->format('Y-m-d'),
            'assets' => [
                'current_assets' => $currentAssets,
                'non_current_assets' => $nonCurrentAssets,
                'total_assets' => round($totalAssets, 2),
            ],
            'liabilities' => [
                'current_liabilities' => $currentLiabilities,
                'non_current_liabilities' => $nonCurrentLiabilities,
                'total_liabilities' => round($totalLiabilities, 2),
            ],
            'equity' => [
                'capital_and_reserves' => $capitalAndReserves,
                'retained_earnings' => round($retainedEarnings, 2),
                'total_equity' => round($totalEquity, 2),
            ],
            'totals' => [
                'total_assets' => round($totalAssets, 2),
                'total_liabilities_and_equity' => round($totalLiabilities + $totalEquity, 2),
                'balanced' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.01,
            ],
            'mapping_summary' => $this->summarizeMappings($balances),
        ];
    }

    public function exportXBRL(array $reportData): string
    {
        $type = $reportData['type'] ?? 'trial_balance';
        $includeSourceData = $reportData['include_source_data'] ?? true;
        $asOfDate = new \DateTime($reportData['as_of_date'] ?? now()->format('Y-m-d'));
        $startDate = isset($reportData['period']['start_date'])
            ? new \DateTime($reportData['period']['start_date'])
            : new \DateTime($asOfDate->format('Y-01-01'));

        if ($type === 'trial_balance' && !isset($reportData['accounts'])) {
            $reportData = $this->exportTrialBalance($asOfDate);
        }

        if ($type === 'balance_sheet' && !isset($reportData['assets'])) {
            $reportData = $this->exportBalanceSheet($asOfDate);
        }

        if ($type === 'income_statement' && !isset($reportData['revenue'])) {
            $reportData = $this->exportIncomeStatement($startDate, $asOfDate);
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS('http://www.xbrl.org/2003/instance', 'xbrli:xbrl');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:link', 'http://www.w3.org/1999/xlink');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:sg-sfrs', 'urn:noovaerp:sg:sfrs:' . strtolower($this->taxonomyProfile->taxonomyVersion()));
        $dom->appendChild($root);

        $schemaRef = $dom->createElementNS('http://www.w3.org/1999/xlink', 'link:schemaRef');
        $schemaRef->setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:type', 'simple');
        $schemaRef->setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:href', $this->schemaRefHrefForXml());
        $root->appendChild($schemaRef);

        $entityId = $this->currentTenantIdentifier();
        $instantContextId = 'ctx_instant';
        $durationContextId = 'ctx_duration';

        $root->appendChild($this->createContextNode($dom, $instantContextId, $entityId, null, $asOfDate->format('Y-m-d')));
        $root->appendChild($this->createContextNode($dom, $durationContextId, $entityId, $startDate->format('Y-m-d'), $asOfDate->format('Y-m-d')));
        $root->appendChild($this->createUnitNode($dom, 'u_sgd', 'iso4217:SGD'));

        $metadata = $dom->createElement('sg-sfrs:ReportMetadata');
        $metadata->appendChild($this->createElementWithText($dom, 'sg-sfrs:ReportType', $type));
        $metadata->appendChild($this->createElementWithText($dom, 'sg-sfrs:TaxonomyVersion', $reportData['taxonomy_version'] ?? $this->taxonomyProfile->taxonomyVersion()));
        $metadata->appendChild($this->createElementWithText($dom, 'sg-sfrs:GeneratedAt', now()->toIso8601String()));
        $root->appendChild($metadata);

        $this->appendReportFacts($dom, $root, $reportData, $type, $type === 'income_statement' ? $durationContextId : $instantContextId);
        if ($includeSourceData) {
            $this->appendChartOfAccountsSection($dom, $root, $asOfDate);
            $this->appendJournalEntriesSection($dom, $root, $type === 'income_statement' ? $startDate : null, $asOfDate);
        }

        $xml = $dom->saveXML();
        $shouldValidateTaxonomy = (bool) ($reportData['validate_taxonomy'] ?? false);

        if ($shouldValidateTaxonomy) {
            $validation = $this->validateXbrlXml($xml);

            $strictSchemaValidation = (bool) ($reportData['strict_schema_validation'] ?? false);
            if ($strictSchemaValidation && !$validation['valid']) {
                $errorMessage = implode('; ', $validation['errors']);
                throw new \RuntimeException('SG XBRL schema validation failed: ' . $errorMessage);
            }
        } else {
            $schemaPath = $this->resolveTaxonomyEntryPointXsdPath();
            $passiveErrors = [];
            if (!$schemaPath || !is_file($schemaPath)) {
                $passiveErrors[] = 'ACRA taxonomy entry point XSD not found at configured path';
            }
            $this->lastXbrlValidation = [
                'validated' => false,
                'valid' => false,
                'errors' => $passiveErrors,
                'warnings' => empty($passiveErrors) ? ['Taxonomy validation was skipped for this request'] : [],
                'entry_point_xsd' => $schemaPath,
                'schema_ref_href' => $this->schemaRefHrefForXml(),
            ];
        }

        return $xml;
    }

    public function validateReport(array $report): array
    {
        $errors = [];

        if (!isset($report['type'])) {
            $errors[] = 'Report type is required';
        }

        if ($report['type'] === 'trial_balance') {
            if (isset($report['totals'])) {
                if (!$report['totals']['balanced']) {
                    $errors[] = 'Trial balance does not balance';
                }
            }
        }

        if (($report['type'] ?? null) === 'balance_sheet') {
            if (!($report['totals']['balanced'] ?? false)) {
                $errors[] = 'Balance sheet does not balance';
            }
        }

        $mappingSummary = $report['mapping_summary'] ?? null;
        if (is_array($mappingSummary) && ($mappingSummary['unmapped_count'] ?? 0) > 0) {
            $errors[] = 'One or more accounts are not mapped to the SFRS taxonomy';
        }

        if (empty($report['taxonomy_version'])) {
            $errors[] = 'Taxonomy version is required';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    public function getSupportedFormats(): array
    {
        return ['json', 'xbrl', 'xml'];
    }

    public function validateXbrlXml(string $xml): array
    {
        $schemaPath = $this->resolveTaxonomyEntryPointXsdPath();

        $result = [
            'validated' => false,
            'valid' => false,
            'errors' => [],
            'warnings' => [],
            'entry_point_xsd' => $schemaPath,
            'schema_ref_href' => $this->schemaRefHrefForXml(),
        ];

        if (!$schemaPath || !is_file($schemaPath)) {
            $result['errors'][] = 'ACRA taxonomy entry point XSD not found at configured path';
            $this->lastXbrlValidation = $result;

            return $result;
        }

        libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        if (!$dom->loadXML($xml)) {
            $result['errors'][] = 'Generated XBRL XML is not well-formed';
            $result['errors'] = array_merge($result['errors'], $this->consumeLibxmlErrors());
            libxml_clear_errors();
            $this->lastXbrlValidation = $result;

            return $result;
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $isValid = @$dom->schemaValidate($schemaPath);
        $libxmlMessages = $this->consumeLibxmlErrors();
        libxml_clear_errors();

        $result['validated'] = true;
        $result['valid'] = (bool) $isValid;
        if (!$isValid) {
            $result['errors'] = $libxmlMessages;
            Log::warning('SG XBRL failed ACRA taxonomy validation', [
                'entry_point_xsd' => $schemaPath,
                'errors' => $libxmlMessages,
            ]);
        }

        $this->lastXbrlValidation = $result;

        return $result;
    }

    public function getLastXbrlValidation(): array
    {
        return $this->lastXbrlValidation;
    }

    private function buildAsOfBalances(Carbon $asOfDate): array
    {
        $accounts = ChartOfAccount::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'subtype', 'opening_balance']);

        $debitTotals = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '<=', $asOfDate->toDateString())
            ->groupBy('jel.account_id')
            ->selectRaw('jel.account_id, COALESCE(SUM(jel.debit), 0) as debit_total, COALESCE(SUM(jel.credit), 0) as credit_total')
            ->pluck('debit_total', 'account_id');

        $creditTotals = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '<=', $asOfDate->toDateString())
            ->groupBy('jel.account_id')
            ->selectRaw('jel.account_id, COALESCE(SUM(jel.credit), 0) as credit_total')
            ->pluck('credit_total', 'account_id');

        return $accounts->map(function (ChartOfAccount $account) use ($debitTotals, $creditTotals) {
            $debitTotal = (float) ($debitTotals[$account->id] ?? 0);
            $creditTotal = (float) ($creditTotals[$account->id] ?? 0);
            $openingBalance = (float) ($account->opening_balance ?? 0);
            $netBalance = $this->calculateSignedBalance($account->type, $debitTotal, $creditTotal, $openingBalance);
            [$debitBalance, $creditBalance] = $this->splitPresentationBalance($account->type, $netBalance);
            $mapping = $this->taxonomyProfile->mapAccount([
                'code' => $account->code,
                'type' => $account->type,
                'subtype' => $account->subtype,
                'name' => $account->name,
            ]);

            return [
                'account_id' => $account->id,
                'account_code' => $account->code,
                'account_name' => $account->name,
                'account_type' => $account->type,
                'account_subtype' => $account->subtype,
                'debit' => round($debitBalance, 2),
                'credit' => round($creditBalance, 2),
                'net_balance' => round(abs($netBalance), 2),
                'signed_balance' => round($netBalance, 2),
                'mapping' => $mapping,
            ];
        })->all();
    }

    private function buildPeriodMovements(Carbon $startDate, Carbon $endDate, array $types): array
    {
        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->whereIn('coa.type', $types)
            ->groupBy('coa.id', 'coa.code', 'coa.name', 'coa.type', 'coa.subtype')
            ->orderBy('coa.code')
            ->get([
                'coa.id',
                'coa.code',
                'coa.name',
                'coa.type',
                'coa.subtype',
                DB::raw('COALESCE(SUM(jel.debit), 0) as total_debit'),
                DB::raw('COALESCE(SUM(jel.credit), 0) as total_credit'),
            ]);

        return $rows->map(function ($row) {
            $amount = $this->calculatePeriodAmount($row->type, (float) $row->total_debit, (float) $row->total_credit);
            $mapping = $this->taxonomyProfile->mapAccount([
                'code' => $row->code,
                'type' => $row->type,
                'subtype' => $row->subtype,
                'name' => $row->name,
            ]);

            return [
                'account_id' => $row->id,
                'account_code' => $row->code,
                'account_name' => $row->name,
                'account_type' => $row->type,
                'account_subtype' => $row->subtype,
                'amount' => round($amount, 2),
                'mapping' => $mapping,
            ];
        })->filter(fn (array $row) => abs($row['amount']) >= 0.01)->values()->all();
    }

    private function calculateRetainedEarnings(Carbon $asOfDate): float
    {
        $income = $this->buildPeriodMovements(Carbon::create(2000, 1, 1), $asOfDate, ['revenue', 'expense']);
        $revenue = collect($income)->where('account_type', 'revenue')->sum('amount');
        $expenses = collect($income)->where('account_type', 'expense')->sum('amount');

        return round($revenue - $expenses, 2);
    }

    private function calculateSignedBalance(string $accountType, float $debitTotal, float $creditTotal, float $openingBalance): float
    {
        if (in_array($accountType, ['asset', 'expense'], true)) {
            return ($debitTotal - $creditTotal) + $openingBalance;
        }

        return ($creditTotal - $debitTotal) + $openingBalance;
    }

    private function splitPresentationBalance(string $accountType, float $signedBalance): array
    {
        if (in_array($accountType, ['asset', 'expense'], true)) {
            return $signedBalance >= 0
                ? [$signedBalance, 0.0]
                : [0.0, abs($signedBalance)];
        }

        return $signedBalance >= 0
            ? [0.0, $signedBalance]
            : [abs($signedBalance), 0.0];
    }

    private function calculatePeriodAmount(string $accountType, float $debitTotal, float $creditTotal): float
    {
        return $accountType === 'revenue'
            ? $creditTotal - $debitTotal
            : $debitTotal - $creditTotal;
    }

    private function groupLinesByTaxonomySection(array $lines, string $statement): array
    {
        $grouped = [];

        foreach ($lines as $line) {
            $mapping = $line['mapping'] ?? null;
            if (!$mapping || ($mapping['statement'] ?? null) !== $statement) {
                continue;
            }

            $section = $mapping['section'];
            $amount = $statement === 'balance_sheet'
                ? (float) ($line['net_balance'] ?? 0)
                : (float) ($line['amount'] ?? 0);

            if (!isset($grouped[$section])) {
                $grouped[$section] = [
                    'items' => [],
                    'total' => 0.0,
                ];
            }

            $grouped[$section]['items'][] = [
                'account_id' => $line['account_id'] ?? null,
                'account_code' => $line['account_code'],
                'account_name' => $line['account_name'],
                'taxonomy_element' => $mapping['element'],
                'taxonomy_label' => $mapping['label'],
                'amount' => round($amount, 2),
            ];
            $grouped[$section]['total'] += $amount;
        }

        foreach ($grouped as $section => $payload) {
            $grouped[$section]['total'] = round($payload['total'], 2);
        }

        return $grouped;
    }

    private function summarizeMappings(array $lines): array
    {
        $mappedCount = 0;
        $unmapped = [];

        foreach ($lines as $line) {
            $mapping = $line['mapping'] ?? null;
            if (is_array($mapping) || isset($line['taxonomy_element'])) {
                $mappedCount++;
                continue;
            }

            $unmapped[] = [
                'account_code' => $line['account_code'] ?? null,
                'account_name' => $line['account_name'] ?? null,
            ];
        }

        return [
            'mapped_count' => $mappedCount,
            'unmapped_count' => count($unmapped),
            'unmapped_accounts' => $unmapped,
        ];
    }

    private function createContextNode(\DOMDocument $dom, string $id, string $entityId, ?string $startDate, string $endDate): \DOMElement
    {
        $context = $dom->createElement('xbrli:context');
        $context->setAttribute('id', $id);

        $entity = $dom->createElement('xbrli:entity');
        $identifier = $this->createElementWithText($dom, 'xbrli:identifier', $entityId);
        $identifier->setAttribute('scheme', 'urn:noovaerp:tenant');
        $entity->appendChild($identifier);
        $context->appendChild($entity);

        $period = $dom->createElement('xbrli:period');
        if ($startDate !== null) {
            $period->appendChild($this->createElementWithText($dom, 'xbrli:startDate', $startDate));
            $period->appendChild($this->createElementWithText($dom, 'xbrli:endDate', $endDate));
        } else {
            $period->appendChild($this->createElementWithText($dom, 'xbrli:instant', $endDate));
        }
        $context->appendChild($period);

        return $context;
    }

    private function createElementWithText(\DOMDocument $dom, string $qualifiedName, mixed $value): \DOMElement
    {
        $element = $dom->createElement($qualifiedName);
        $text = (string) ($value ?? '');

        if ($text !== '') {
            $element->appendChild($dom->createTextNode($text));
        }

        return $element;
    }

    private function createUnitNode(\DOMDocument $dom, string $id, string $measure): \DOMElement
    {
        $unit = $dom->createElement('xbrli:unit');
        $unit->setAttribute('id', $id);
        $unit->appendChild($this->createElementWithText($dom, 'xbrli:measure', $measure));

        return $unit;
    }

    private function appendReportFacts(\DOMDocument $dom, \DOMElement $root, array $reportData, string $type, string $contextRef): void
    {
        $facts = $dom->createElement('sg-sfrs:ReportFacts');
        $root->appendChild($facts);

        if ($type === 'trial_balance') {
            foreach ($reportData['accounts'] ?? [] as $account) {
                $item = $dom->createElement('sg-sfrs:StatementLineItem');
                $item->setAttribute('contextRef', $contextRef);
                $item->appendChild($this->createElementWithText($dom, 'sg-sfrs:AccountCode', (string) $account['account_code']));
                $item->appendChild($this->createElementWithText($dom, 'sg-sfrs:AccountName', (string) $account['account_name']));
                $item->appendChild($this->createElementWithText($dom, 'sg-sfrs:TaxonomyElement', (string) ($account['taxonomy_element'] ?? '')));
                $item->appendChild($this->createElementWithText($dom, 'sg-sfrs:DebitAmount', number_format((float) ($account['debit'] ?? 0), 2, '.', '')));
                $item->appendChild($this->createElementWithText($dom, 'sg-sfrs:CreditAmount', number_format((float) ($account['credit'] ?? 0), 2, '.', '')));
                $facts->appendChild($item);
            }

            return;
        }

        $sections = $type === 'income_statement'
            ? ['revenue', 'cost_of_goods_sold', 'operating_expenses', 'other_income', 'other_expenses']
            : ['assets', 'liabilities', 'equity'];

        foreach ($sections as $sectionKey) {
            if (!isset($reportData[$sectionKey])) {
                continue;
            }

            $sectionNode = $dom->createElement('sg-sfrs:ReportSection');
            $sectionNode->setAttribute('name', $sectionKey);

            $payload = $reportData[$sectionKey];
            if (isset($payload['items'])) {
                foreach ($payload['items'] as $itemData) {
                    $sectionNode->appendChild($this->createFactLineNode($dom, $itemData, $contextRef));
                }
            } else {
                foreach ($payload as $subSection => $subPayload) {
                    if (!is_array($subPayload) || !isset($subPayload['items'])) {
                        continue;
                    }

                    foreach ($subPayload['items'] as $itemData) {
                        $factLine = $this->createFactLineNode($dom, $itemData, $contextRef);
                        $factLine->setAttribute('section', $subSection);
                        $sectionNode->appendChild($factLine);
                    }
                }
            }

            $facts->appendChild($sectionNode);
        }
    }

    private function createFactLineNode(\DOMDocument $dom, array $itemData, string $contextRef): \DOMElement
    {
        $item = $dom->createElement('sg-sfrs:StatementLineItem');
        $item->setAttribute('contextRef', $contextRef);
        $item->appendChild($this->createElementWithText($dom, 'sg-sfrs:AccountCode', (string) ($itemData['account_code'] ?? '')));
        $item->appendChild($this->createElementWithText($dom, 'sg-sfrs:AccountName', (string) ($itemData['account_name'] ?? '')));
        $item->appendChild($this->createElementWithText($dom, 'sg-sfrs:TaxonomyElement', (string) ($itemData['taxonomy_element'] ?? '')));
        $item->appendChild($this->createElementWithText($dom, 'sg-sfrs:TaxonomyLabel', (string) ($itemData['taxonomy_label'] ?? '')));
        $item->appendChild($this->createElementWithText($dom, 'sg-sfrs:Amount', number_format((float) ($itemData['amount'] ?? 0), 2, '.', '')));

        return $item;
    }

    private function appendChartOfAccountsSection(\DOMDocument $dom, \DOMElement $root, \DateTime $asOfDate): void
    {
        $coaNode = $dom->createElement('sg-sfrs:ChartOfAccounts');
        foreach ($this->buildAsOfBalances(Carbon::instance($asOfDate)) as $account) {
            $accountNode = $dom->createElement('sg-sfrs:Account');
            $accountNode->appendChild($this->createElementWithText($dom, 'sg-sfrs:Code', $account['account_code']));
            $accountNode->appendChild($this->createElementWithText($dom, 'sg-sfrs:Name', $account['account_name']));
            $accountNode->appendChild($this->createElementWithText($dom, 'sg-sfrs:Type', $account['account_type']));
            $accountNode->appendChild($this->createElementWithText($dom, 'sg-sfrs:Subtype', (string) ($account['account_subtype'] ?? '')));
            $accountNode->appendChild($this->createElementWithText($dom, 'sg-sfrs:TaxonomyElement', (string) ($account['mapping']['element'] ?? '')));
            $coaNode->appendChild($accountNode);
        }
        $root->appendChild($coaNode);
    }

    private function appendJournalEntriesSection(\DOMDocument $dom, \DOMElement $root, ?\DateTime $startDate, \DateTime $endDate): void
    {
        $query = DB::table('journal_entries as je')
            ->join('journal_entry_lines as jel', 'jel.journal_entry_id', '=', 'je.id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.status', 'posted')
            ->whereDate('je.entry_date', '<=', $endDate->format('Y-m-d'));

        if ($startDate !== null) {
            $query->whereDate('je.entry_date', '>=', $startDate->format('Y-m-d'));
        }

        $entries = $query
            ->orderBy('je.entry_date')
            ->orderBy('je.id')
            ->get([
                'je.id as journal_entry_id',
                'je.entry_number',
                'je.entry_date',
                'je.reference',
                'je.description as journal_description',
                'coa.code as account_code',
                'coa.name as account_name',
                'coa.type as account_type',
                'coa.subtype as account_subtype',
                'jel.debit',
                'jel.credit',
                'jel.description as line_description',
                'jel.reference as line_reference',
            ])
            ->groupBy('journal_entry_id');

        $entriesNode = $dom->createElement('sg-sfrs:JournalEntries');
        foreach ($entries as $journalEntryId => $lines) {
            $first = $lines->first();
            $entryNode = $dom->createElement('sg-sfrs:JournalEntry');
            $entryNode->setAttribute('id', (string) $journalEntryId);
            $entryNode->appendChild($this->createElementWithText($dom, 'sg-sfrs:EntryNumber', (string) $first->entry_number));
            $entryNode->appendChild($this->createElementWithText($dom, 'sg-sfrs:EntryDate', (string) $first->entry_date));
            $entryNode->appendChild($this->createElementWithText($dom, 'sg-sfrs:Reference', (string) ($first->reference ?? '')));
            $entryNode->appendChild($this->createElementWithText($dom, 'sg-sfrs:Description', (string) ($first->journal_description ?? '')));

            foreach ($lines as $line) {
                $mapping = $this->taxonomyProfile->mapAccount([
                    'code' => $line->account_code,
                    'type' => $line->account_type,
                    'subtype' => $line->account_subtype,
                    'name' => $line->account_name,
                ]);
                $lineNode = $dom->createElement('sg-sfrs:Line');
                $lineNode->appendChild($this->createElementWithText($dom, 'sg-sfrs:AccountCode', (string) $line->account_code));
                $lineNode->appendChild($this->createElementWithText($dom, 'sg-sfrs:AccountName', (string) $line->account_name));
                $lineNode->appendChild($this->createElementWithText($dom, 'sg-sfrs:TaxonomyElement', (string) ($mapping['element'] ?? '')));
                $lineNode->appendChild($this->createElementWithText($dom, 'sg-sfrs:Debit', number_format((float) $line->debit, 2, '.', '')));
                $lineNode->appendChild($this->createElementWithText($dom, 'sg-sfrs:Credit', number_format((float) $line->credit, 2, '.', '')));
                $lineNode->appendChild($this->createElementWithText($dom, 'sg-sfrs:Description', (string) ($line->line_description ?? '')));
                $entryNode->appendChild($lineNode);
            }

            $entriesNode->appendChild($entryNode);
        }

        $root->appendChild($entriesNode);
    }

    private function currentTenantIdentifier(): string
    {
        if (class_exists('Tenancy')) {
            try {
                return (string) (\Tenancy::current()?->id ?? 'SG');
            } catch (\Throwable) {
                return 'SG';
            }
        }

        return 'SG';
    }

    private function taxonomyConfig(): array
    {
        if (function_exists('config')) {
            try {
                return config('compliance.singapore.taxonomy', []);
            } catch (\Throwable) {
            }
        }

        $defaultPackagePath = dirname(__DIR__, 2) . '/Taxonomy/ACRA-SFRS-2024';

        return [
            'enabled' => filter_var(getenv('COMPLIANCE_SG_TAXONOMY_VALIDATION_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN),
            'strict' => filter_var(getenv('COMPLIANCE_SG_TAXONOMY_STRICT') ?: 'false', FILTER_VALIDATE_BOOLEAN),
            'package_path' => getenv('COMPLIANCE_SG_TAXONOMY_PACKAGE_PATH') ?: $defaultPackagePath,
            'entry_point_xsd' => getenv('COMPLIANCE_SG_TAXONOMY_ENTRY_XSD') ?: 'acra-sfrs-2024-entrypoint.xsd',
            'schema_ref_href' => getenv('COMPLIANCE_SG_TAXONOMY_SCHEMA_HREF') ?: 'acra-sfrs-2024-entrypoint.xsd',
        ];
    }

    private function schemaRefHrefForXml(): string
    {
        return (string) ($this->taxonomyConfig()['schema_ref_href'] ?? $this->taxonomyProfile->schemaHref());
    }

    private function resolveTaxonomyEntryPointXsdPath(): ?string
    {
        $taxonomy = $this->taxonomyConfig();
        $entryPoint = $taxonomy['entry_point_xsd'] ?? null;
        $packagePath = $taxonomy['package_path'] ?? null;

        if (!$entryPoint) {
            return null;
        }

        if (str_starts_with($entryPoint, '/')) {
            return $entryPoint;
        }

        if (!$packagePath) {
            return null;
        }

        return rtrim((string) $packagePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string) $entryPoint, DIRECTORY_SEPARATOR);
    }

    private function consumeLibxmlErrors(): array
    {
        $messages = [];
        foreach (libxml_get_errors() as $error) {
            $level = match ($error->level) {
                LIBXML_ERR_WARNING => 'warning',
                LIBXML_ERR_ERROR => 'error',
                LIBXML_ERR_FATAL => 'fatal',
                default => 'unknown',
            };

            $messages[] = sprintf(
                '[%s] Line %d: %s',
                strtoupper($level),
                (int) $error->line,
                trim($error->message)
            );
        }

        return $messages;
    }
}
