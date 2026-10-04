<?php

namespace Modules\AccountingCountry\Malaysia\Services;

use App\Accounting\Contracts\StatutoryReportExporterContract;
use App\Accounting\Exceptions\StatutoryReportNotImplemented;

/**
 * Malaysia MFRS Report Exporter
 *
 * #764 (A4): this exporter was a placeholder that returned hard-coded zeros
 * with success:true (the trial balance even queried a non-existent table), so
 * a user could download an "MFRS balance sheet" of zeros. Until the shared
 * statutory-statements engine delivers the MFRS/MPERS layouts (tracked in
 * #765/#766/#767/#776/#782), every report build fails loudly with 501 — a
 * statement is never emitted from stubbed figures.
 */
class MFRSReportExporter implements StatutoryReportExporterContract
{
    private const NOT_IMPLEMENTED_MESSAGE = 'Malaysia MFRS/MPERS statutory statements are not implemented yet; no statement was produced. Tracked in #782 (SSM MBRS) / #776.';

    /**
     * Export trial balance
     */
    public function exportTrialBalance(\DateTime $asOfDate, array $options = []): array
    {
        throw new StatutoryReportNotImplemented(self::NOT_IMPLEMENTED_MESSAGE);
    }

    /**
     * Export income statement
     */
    public function exportIncomeStatement(
        \DateTime $startDate,
        \DateTime $endDate,
        array $options = []
    ): array {
        throw new StatutoryReportNotImplemented(self::NOT_IMPLEMENTED_MESSAGE);
    }

    /**
     * Export balance sheet
     */
    public function exportBalanceSheet(\DateTime $asOfDate, array $options = []): array
    {
        throw new StatutoryReportNotImplemented(self::NOT_IMPLEMENTED_MESSAGE);
    }

    /**
     * Export XBRL GL
     */
    public function exportXBRL(array $reportData): string
    {
        throw new StatutoryReportNotImplemented(self::NOT_IMPLEMENTED_MESSAGE);
    }

    /**
     * Validate report
     */
    public function validateReport(array $report): array
    {
        $errors = [];

        if (! isset($report['type'])) {
            $errors[] = 'Report type is required';
        }

        if (($report['type'] ?? null) === 'trial_balance'
            && ! ($report['totals']['balanced'] ?? false)) {
            $errors[] = 'Trial balance does not balance';
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Supported export formats for MY statutory reporting.
     */
    public function getSupportedFormats(): array
    {
        return ['json', 'xbrl', 'xml'];
    }
}
