<?php

namespace App\Accounting\Services;

use App\Accounting\Plugin\CountryPluginRegistry;
use Illuminate\Support\Facades\Log;

class ComplianceService
{
    protected CountryPluginRegistry $pluginRegistry;

    public function __construct(CountryPluginRegistry $pluginRegistry)
    {
        $this->pluginRegistry = $pluginRegistry;
    }

    public function exportStatutoryReport(
        string $countryCode,
        string $reportType,
        \DateTime $asOfDate,
        array $options = []
    ): array {
        $exporter = $this->pluginRegistry->resolve($countryCode, 'exporter');

        if (!$exporter) {
            return ['success' => false, 'message' => "No exporter available for {$countryCode}"];
        }

        try {
            $report = match($reportType) {
                'balance_sheet' => $exporter->exportBalanceSheet($asOfDate, $options),
                'income_statement' => $exporter->exportIncomeStatement(
                    isset($options['start_date'])
                        ? ($options['start_date'] instanceof \DateTime
                            ? $options['start_date']
                            : new \DateTime((string) $options['start_date']))
                        : $asOfDate,
                    $asOfDate,
                    $options
                ),
                'trial_balance' => $exporter->exportTrialBalance($asOfDate, $options),
                default => throw new \InvalidArgumentException("Unknown report type: {$reportType}"),
            };

            $validation = $exporter->validateReport($report);
            if (!$validation['valid']) {
                $allowUnbalanced = (bool) ($options['allow_unbalanced'] ?? false);

                if (!$allowUnbalanced) {
                    return ['success' => false, 'errors' => $validation['errors']];
                }

                $nonBalanceErrors = array_values(array_filter(
                    $validation['errors'] ?? [],
                    fn ($error) => !$this->isBalanceValidationError((string) $error)
                ));

                if (!empty($nonBalanceErrors)) {
                    return ['success' => false, 'errors' => $nonBalanceErrors];
                }

                Log::warning('Compliance export proceeded with unbalanced report', [
                    'country' => strtoupper($countryCode),
                    'report_type' => $reportType,
                    'errors' => $validation['errors'] ?? [],
                ]);
            }

            $format = strtolower((string) ($options['format'] ?? 'json'));
            $xbrlXml = null;
            $xbrlValidation = null;

            if (in_array($format, ['xbrl', 'xml'], true)) {
                $xbrlXml = $exporter->exportXBRL(array_merge($report, [
                    'type' => $report['type'] ?? str_replace('-', '_', $reportType),
                    'validate_taxonomy' => (bool) ($options['validate_taxonomy'] ?? false),
                    'strict_schema_validation' => (bool) ($options['strict_schema_validation'] ?? false),
                ]));

                if (method_exists($exporter, 'getLastXbrlValidation')) {
                    $xbrlValidation = $exporter->getLastXbrlValidation();
                }
            }

            return [
                'success' => true,
                'report' => $report,
                'format' => $format,
                'xbrl_xml' => $xbrlXml,
                'xbrl_validation' => $xbrlValidation,
            ];

        } catch (\Exception $e) {
            Log::error('Statutory report export failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Export error: ' . $e->getMessage()];
        }
    }

    private function isBalanceValidationError(string $error): bool
    {
        return str_contains(strtolower($error), 'does not balance');
    }
}
