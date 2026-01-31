<?php

namespace Modules\FinancialReporting\Services\Export;

use Illuminate\Support\Facades\Storage;

class CsvExportService
{
    /**
     * Export report data to CSV
     */
    public function exportReport(array $reportData, string $filename = null): string
    {
        $filename = $filename ?? 'report_' . now()->format('Y-m-d_His') . '.csv';
        $path = 'reports/' . $filename;

        // Create CSV content
        $csvContent = $this->generateCsvContent($reportData);

        // Store the file
        Storage::disk('local')->put($path, $csvContent);

        return storage_path('app/' . $path);
    }

    /**
     * Generate CSV content from report data
     */
    protected function generateCsvContent(array $reportData): string
    {
        $output = fopen('php://temp', 'r+');

        // Add title and metadata
        if (isset($reportData['title'])) {
            fputcsv($output, [$reportData['title']]);
            fputcsv($output, []); // Empty line
        }

        // Add period information
        if (isset($reportData['period'])) {
            fputcsv($output, ['Period:', $reportData['period']['start_date'] ?? '', 'to', $reportData['period']['end_date'] ?? '']);
            fputcsv($output, []); // Empty line
        }

        // Add generated timestamp
        if (isset($reportData['generated_at'])) {
            fputcsv($output, ['Generated:', $reportData['generated_at']]);
            fputcsv($output, []); // Empty line
        }

        // Add main data
        if (isset($reportData['data']) && is_array($reportData['data']) && !empty($reportData['data'])) {
            // Get headers from first data row
            $headers = array_keys($reportData['data'][0]);
            fputcsv($output, $headers);

            // Add data rows
            foreach ($reportData['data'] as $row) {
                fputcsv($output, array_values($row));
            }

            fputcsv($output, []); // Empty line
        }

        // Add summary if present
        if (isset($reportData['summary']) && is_array($reportData['summary'])) {
            fputcsv($output, ['Summary']);
            foreach ($reportData['summary'] as $key => $value) {
                $label = ucwords(str_replace('_', ' ', $key));
                fputcsv($output, [$label, $value]);
            }
        }

        // Get the content
        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        return $csvContent;
    }

    /**
     * Export multiple reports to a single CSV with sections
     */
    public function exportMultipleReports(array $reports, string $filename = null): string
    {
        $filename = $filename ?? 'reports_' . now()->format('Y-m-d_His') . '.csv';
        $path = 'reports/' . $filename;

        $output = fopen('php://temp', 'r+');

        foreach ($reports as $index => $reportData) {
            if ($index > 0) {
                // Add separator between reports
                fputcsv($output, []);
                fputcsv($output, ['='.str_repeat('=', 50)]);
                fputcsv($output, []);
            }

            // Add report title
            if (isset($reportData['title'])) {
                fputcsv($output, [$reportData['title']]);
                fputcsv($output, []);
            }

            // Add period
            if (isset($reportData['period'])) {
                fputcsv($output, ['Period:', $reportData['period']['start_date'] ?? '', 'to', $reportData['period']['end_date'] ?? '']);
                fputcsv($output, []);
            }

            // Add data
            if (isset($reportData['data']) && is_array($reportData['data']) && !empty($reportData['data'])) {
                $headers = array_keys($reportData['data'][0]);
                fputcsv($output, $headers);

                foreach ($reportData['data'] as $row) {
                    fputcsv($output, array_values($row));
                }

                fputcsv($output, []);
            }

            // Add summary
            if (isset($reportData['summary']) && is_array($reportData['summary'])) {
                fputcsv($output, ['Summary']);
                foreach ($reportData['summary'] as $key => $value) {
                    $label = ucwords(str_replace('_', ' ', $key));
                    fputcsv($output, [$label, $value]);
                }
            }
        }

        // Get content and save
        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);

        Storage::disk('local')->put($path, $csvContent);

        return storage_path('app/' . $path);
    }

    /**
     * Stream CSV download (for large datasets)
     */
    public function streamCsv(array $reportData, string $filename = null): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filename = $filename ?? 'report_' . now()->format('Y-m-d_His') . '.csv';

        return response()->stream(function() use ($reportData) {
            $output = fopen('php://output', 'w');

            // Add title and metadata
            if (isset($reportData['title'])) {
                fputcsv($output, [$reportData['title']]);
                fputcsv($output, []);
            }

            // Add period
            if (isset($reportData['period'])) {
                fputcsv($output, ['Period:', $reportData['period']['start_date'] ?? '', 'to', $reportData['period']['end_date'] ?? '']);
                fputcsv($output, []);
            }

            // Add data
            if (isset($reportData['data']) && is_array($reportData['data']) && !empty($reportData['data'])) {
                $headers = array_keys($reportData['data'][0]);
                fputcsv($output, $headers);

                foreach ($reportData['data'] as $row) {
                    fputcsv($output, array_values($row));
                }

                fputcsv($output, []);
            }

            // Add summary
            if (isset($reportData['summary']) && is_array($reportData['summary'])) {
                fputcsv($output, ['Summary']);
                foreach ($reportData['summary'] as $key => $value) {
                    $label = ucwords(str_replace('_', ' ', $key));
                    fputcsv($output, [$label, $value]);
                }
            }

            fclose($output);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Delete old export files
     */
    public function cleanupOldExports(int $daysOld = 7): int
    {
        $files = Storage::disk('local')->files('reports');
        $deleted = 0;
        $cutoffTime = now()->subDays($daysOld)->timestamp;

        foreach ($files as $file) {
            if (Storage::disk('local')->lastModified($file) < $cutoffTime) {
                Storage::disk('local')->delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
