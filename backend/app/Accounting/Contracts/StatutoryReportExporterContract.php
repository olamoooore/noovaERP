<?php

namespace App\Accounting\Contracts;

interface StatutoryReportExporterContract
{
    public function exportTrialBalance(\DateTime $asOfDate, array $options = []): array;

    public function exportBalanceSheet(\DateTime $asOfDate, array $options = []): array;

    public function exportIncomeStatement(\DateTime $startDate, \DateTime $endDate, array $options = []): array;

    public function exportXBRL(array $reportData): string;

    public function validateReport(array $reportData): array;

    public function getSupportedFormats(): array;
}
