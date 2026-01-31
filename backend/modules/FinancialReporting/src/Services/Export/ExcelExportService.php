<?php

namespace Modules\FinancialReporting\Services\Export;

use Illuminate\Support\Facades\Storage;

class ExcelExportService
{
    /**
     * Export report data to Excel
     * 
     * Note: This requires PhpSpreadsheet library
     * Install with: composer require phpoffice/phpspreadsheet
     */
    public function exportReport(array $reportData, string $filename = null): string
    {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            throw new \Exception('PhpSpreadsheet library is not installed. Run: composer require phpoffice/phpspreadsheet');
        }

        $filename = $filename ?? 'report_' . now()->format('Y-m-d_His') . '.xlsx';
        $path = 'reports/' . $filename;

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $currentRow = 1;

        // Add title
        if (isset($reportData['title'])) {
            $sheet->setCellValue('A' . $currentRow, $reportData['title']);
            $sheet->getStyle('A' . $currentRow)->getFont()->setBold(true)->setSize(16);
            $currentRow += 2;
        }

        // Add period
        if (isset($reportData['period'])) {
            $periodText = 'Period: ' . ($reportData['period']['start_date'] ?? '') . ' to ' . ($reportData['period']['end_date'] ?? '');
            $sheet->setCellValue('A' . $currentRow, $periodText);
            $sheet->getStyle('A' . $currentRow)->getFont()->setItalic(true);
            $currentRow += 2;
        }

        // Add data table
        if (isset($reportData['data']) && is_array($reportData['data']) && !empty($reportData['data'])) {
            // Headers
            $headers = array_keys($reportData['data'][0]);
            $col = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue($col . $currentRow, ucwords(str_replace('_', ' ', $header)));
                $sheet->getStyle($col . $currentRow)->getFont()->setBold(true);
                $sheet->getStyle($col . $currentRow)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('E0E0E0');
                $col++;
            }
            $currentRow++;

            // Data rows
            foreach ($reportData['data'] as $row) {
                $col = 'A';
                foreach ($row as $value) {
                    $sheet->setCellValue($col . $currentRow, $value);
                    
                    // Format numbers
                    if (is_numeric($value) && strpos($value, '.') !== false) {
                        $sheet->getStyle($col . $currentRow)->getNumberFormat()
                            ->setFormatCode('#,##0.00');
                    }
                    
                    $col++;
                }
                $currentRow++;
            }

            $currentRow += 2;

            // Auto-size columns
            foreach (range('A', $col) as $columnID) {
                $sheet->getColumnDimension($columnID)->setAutoSize(true);
            }
        }

        // Add summary
        if (isset($reportData['summary']) && is_array($reportData['summary'])) {
            $sheet->setCellValue('A' . $currentRow, 'Summary');
            $sheet->getStyle('A' . $currentRow)->getFont()->setBold(true)->setSize(14);
            $currentRow++;

            foreach ($reportData['summary'] as $key => $value) {
                $label = ucwords(str_replace('_', ' ', $key));
                $sheet->setCellValue('A' . $currentRow, $label);
                $sheet->setCellValue('B' . $currentRow, $value);
                $sheet->getStyle('A' . $currentRow)->getFont()->setBold(true);
                
                // Format numbers
                if (is_numeric($value) && strpos($value, '.') !== false) {
                    $sheet->getStyle('B' . $currentRow)->getNumberFormat()
                        ->setFormatCode('#,##0.00');
                }
                
                $currentRow++;
            }
        }

        // Add borders to data table
        if (isset($reportData['data']) && !empty($reportData['data'])) {
            $lastCol = chr(64 + count(array_keys($reportData['data'][0])));
            $lastRow = count($reportData['data']) + (isset($reportData['title']) ? 3 : 1);
            
            $styleArray = [
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                    ],
                ],
            ];
            
            $sheet->getStyle('A3:' . $lastCol . $lastRow)->applyFromArray($styleArray);
        }

        // Save file
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $fullPath = storage_path('app/' . $path);
        
        // Ensure directory exists
        $directory = dirname($fullPath);
        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $writer->save($fullPath);

        return $fullPath;
    }

    /**
     * Export with charts
     */
    public function exportWithCharts(array $reportData, array $chartConfig, string $filename = null): string
    {
        if (!class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            throw new \Exception('PhpSpreadsheet library is not installed.');
        }

        // First create the basic report
        $path = $this->exportReport($reportData, $filename);

        // TODO: Add chart generation logic here
        // This would require additional PhpSpreadsheet chart configuration

        return $path;
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
            if (str_ends_with($file, '.xlsx') && Storage::disk('local')->lastModified($file) < $cutoffTime) {
                Storage::disk('local')->delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
