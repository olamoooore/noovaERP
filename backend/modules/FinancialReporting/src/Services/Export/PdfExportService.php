<?php

namespace Modules\FinancialReporting\Services\Export;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

class PdfExportService
{
    private function tenantExportDirectory(): string
    {
        if (! function_exists('tenant') || ! tenant()) {
            throw new \RuntimeException('Report exports require an active tenant context.');
        }

        return 'reports/'.tenant('id');
    }

    private function exportFilename(?string $filename, string $extension): string
    {
        $name = $filename ? pathinfo($filename, PATHINFO_FILENAME) : 'report_'.now()->format('Y-m-d_His');
        $safeName = Str::of($name)->ascii()->replaceMatches('/[^A-Za-z0-9._-]+/', '_')->trim('_')->value();
        $safeName = $safeName !== '' ? $safeName : 'report';

        return $safeName.'_'.Str::lower(Str::random(16)).'.'.$extension;
    }

    /**
     * Export report data to PDF
     *
     * Note: This requires DomPDF library
     * Install with: composer require barryvdh/laravel-dompdf
     */
    public function exportReport(array $reportData, string $template = 'default', ?string $filename = null): string
    {
        if (! class_exists('\Barryvdh\DomPDF\Facade\Pdf')) {
            throw new \Exception('DomPDF library is not installed. Run: composer require barryvdh/laravel-dompdf');
        }

        $filename = $this->exportFilename($filename, 'pdf');
        $path = $this->tenantExportDirectory().'/'.$filename;

        // Generate HTML content
        $html = $this->generateHtmlContent($reportData, $template);

        // Create PDF
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'portrait');

        // Save file
        $fullPath = storage_path('app/'.$path);

        // Ensure directory exists
        $directory = dirname($fullPath);
        if (! file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        $pdf->save($fullPath);

        return $fullPath;
    }

    /**
     * Generate HTML content for PDF
     */
    protected function generateHtmlContent(array $reportData, string $template): string
    {
        // Check if custom template exists
        $viewPath = "financial-reporting::reports.{$template}";

        if (View::exists($viewPath)) {
            return View::make($viewPath, ['report' => $reportData])->render();
        }

        // Use default template
        return $this->getDefaultTemplate($reportData);
    }

    /**
     * Get default HTML template
     */
    protected function getDefaultTemplate(array $reportData): string
    {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>'.($reportData['title'] ?? 'Report').'</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
            color: #2c3e50;
        }
        .period {
            text-align: center;
            font-style: italic;
            color: #666;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th {
            background-color: #2c3e50;
            color: white;
            padding: 10px;
            text-align: left;
            font-weight: bold;
        }
        td {
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .summary {
            margin-top: 30px;
            padding: 15px;
            background-color: #f0f0f0;
            border-left: 4px solid #2c3e50;
        }
        .summary h2 {
            margin-top: 0;
            font-size: 18px;
            color: #2c3e50;
        }
        .summary-item {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #ddd;
        }
        .summary-item:last-child {
            border-bottom: none;
        }
        .summary-label {
            font-weight: bold;
        }
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 10px;
            color: #999;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        .number {
            text-align: right;
        }
    </style>
</head>
<body>';

        // Header
        $html .= '<div class="header">';
        $html .= '<h1>'.($reportData['title'] ?? 'Report').'</h1>';
        $html .= '</div>';

        // Period
        if (isset($reportData['period'])) {
            $html .= '<div class="period">';
            $html .= 'Period: '.($reportData['period']['start_date'] ?? '').' to '.($reportData['period']['end_date'] ?? '');
            $html .= '</div>';
        }

        // Data table
        if (isset($reportData['data']) && is_array($reportData['data']) && ! empty($reportData['data'])) {
            $html .= '<table>';

            // Headers
            $html .= '<thead><tr>';
            foreach (array_keys($reportData['data'][0]) as $header) {
                $html .= '<th>'.ucwords(str_replace('_', ' ', $header)).'</th>';
            }
            $html .= '</tr></thead>';

            // Data rows
            $html .= '<tbody>';
            foreach ($reportData['data'] as $row) {
                $html .= '<tr>';
                foreach ($row as $value) {
                    $class = is_numeric($value) ? 'number' : '';
                    $html .= '<td class="'.$class.'">'.htmlspecialchars($value).'</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody>';

            $html .= '</table>';
        }

        // Summary
        if (isset($reportData['summary']) && is_array($reportData['summary'])) {
            $html .= '<div class="summary">';
            $html .= '<h2>Summary</h2>';

            foreach ($reportData['summary'] as $key => $value) {
                $label = ucwords(str_replace('_', ' ', $key));
                $html .= '<div class="summary-item">';
                $html .= '<span class="summary-label">'.$label.':</span>';
                $html .= '<span>'.htmlspecialchars($value).'</span>';
                $html .= '</div>';
            }

            $html .= '</div>';
        }

        // Footer
        $html .= '<div class="footer">';
        $html .= 'Generated on '.now()->format('Y-m-d H:i:s');
        $html .= '</div>';

        $html .= '</body></html>';

        return $html;
    }

    /**
     * Export with landscape orientation
     */
    public function exportLandscape(array $reportData, string $template = 'default', ?string $filename = null): string
    {
        if (! class_exists('\Barryvdh\DomPDF\Facade\Pdf')) {
            throw new \Exception('DomPDF library is not installed.');
        }

        $filename = $this->exportFilename($filename, 'pdf');
        $path = $this->tenantExportDirectory().'/'.$filename;

        $html = $this->generateHtmlContent($reportData, $template);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html);
        $pdf->setPaper('a4', 'landscape');

        $fullPath = storage_path('app/'.$path);
        $directory = dirname($fullPath);
        if (! file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        $pdf->save($fullPath);

        return $fullPath;
    }

    /**
     * Delete old export files
     */
    public function cleanupOldExports(int $daysOld = 7): int
    {
        $files = Storage::disk('local')->allFiles($this->tenantExportDirectory());
        $deleted = 0;
        $cutoffTime = now()->subDays($daysOld)->timestamp;

        foreach ($files as $file) {
            if (str_ends_with($file, '.pdf') && Storage::disk('local')->lastModified($file) < $cutoffTime) {
                Storage::disk('local')->delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
