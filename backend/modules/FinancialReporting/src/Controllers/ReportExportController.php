<?php

namespace Modules\FinancialReporting\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Modules\FinancialReporting\Services\Export\PdfExportService;
use Modules\FinancialReporting\Services\Export\ExcelExportService;
use Modules\FinancialReporting\Services\Export\CsvExportService;

class ReportExportController extends Controller
{
    private function currentTenantId(): string
    {
        if (!function_exists('tenant') || !tenant()) {
            throw new \RuntimeException('Report export requires an active tenant context.');
        }

        return (string) tenant('id');
    }

    private function buildDownloadUrl(string $filename): string
    {
        return URL::temporarySignedRoute(
            'reports.export.download',
            now()->addMinutes(15),
            ['file' => $filename]
        );
    }

    public function __construct(
        protected PdfExportService $pdfExportService,
        protected ExcelExportService $excelExportService,
        protected CsvExportService $csvExportService
    ) {
    }

    /**
     * Export report to PDF
     */
    public function exportPdf(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report_data' => 'required|array',
            'template' => 'nullable|string',
            'filename' => 'nullable|string',
            'orientation' => 'nullable|in:portrait,landscape',
        ]);

        try {
            $reportData = $validated['report_data'];
            $template = $validated['template'] ?? 'default';
            $filename = $validated['filename'] ?? null;
            $orientation = $validated['orientation'] ?? 'portrait';

            if ($orientation === 'landscape') {
                $filePath = $this->pdfExportService->exportLandscape($reportData, $template, $filename);
            } else {
                $filePath = $this->pdfExportService->exportReport($reportData, $template, $filename);
            }

            // Generate download URL
            $downloadUrl = $this->buildDownloadUrl(basename($filePath));

            return response()->json([
                'success' => true,
                'message' => 'PDF exported successfully',
                'file_path' => $filePath,
                'download_url' => $downloadUrl,
                'filename' => basename($filePath),
            ]);

        } catch (\Exception $e) {
            Log::error('PDF export failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export report to Excel
     */
    public function exportExcel(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report_data' => 'required|array',
            'filename' => 'nullable|string',
        ]);

        try {
            $reportData = $validated['report_data'];
            $filename = $validated['filename'] ?? null;

            $filePath = $this->excelExportService->exportReport($reportData, $filename);

            $downloadUrl = $this->buildDownloadUrl(basename($filePath));

            return response()->json([
                'success' => true,
                'message' => 'Excel exported successfully',
                'file_path' => $filePath,
                'download_url' => $downloadUrl,
                'filename' => basename($filePath),
            ]);

        } catch (\Exception $e) {
            Log::error('Excel export failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export report to CSV
     */
    public function exportCsv(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report_data' => 'required|array',
            'filename' => 'nullable|string',
        ]);

        try {
            $reportData = $validated['report_data'];
            $filename = $validated['filename'] ?? null;

            $filePath = $this->csvExportService->exportReport($reportData, $filename);

            $downloadUrl = $this->buildDownloadUrl(basename($filePath));

            return response()->json([
                'success' => true,
                'message' => 'CSV exported successfully',
                'file_path' => $filePath,
                'download_url' => $downloadUrl,
                'filename' => basename($filePath),
            ]);

        } catch (\Exception $e) {
            Log::error('CSV export failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Stream CSV download (for large datasets)
     */
    public function streamCsv(Request $request)
    {
        $validated = $request->validate([
            'report_data' => 'required|array',
            'filename' => 'nullable|string',
        ]);

        try {
            $reportData = $validated['report_data'];
            $filename = $validated['filename'] ?? null;

            return $this->csvExportService->streamCsv($reportData, $filename);

        } catch (\Exception $e) {
            Log::error('CSV stream failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download exported file
     */
    public function download(string $file)
    {
        abort_unless(request()->hasValidSignature(), 403, 'Invalid or expired download link');

        $tenantId = $this->currentTenantId();
        $safeFile = basename($file);
        $filePath = storage_path('app/reports/' . $tenantId . '/' . $safeFile);

        if (!file_exists($filePath)) {
            abort(404, 'File not found');
        }

        return response()->download($filePath)->deleteFileAfterSend(false);
    }

    /**
     * Cleanup old export files
     */
    public function cleanup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days_old' => 'nullable|integer|min:1|max:365',
        ]);

        try {
            $daysOld = $validated['days_old'] ?? 7;

            $pdfDeleted = $this->pdfExportService->cleanupOldExports($daysOld);
            $excelDeleted = $this->excelExportService->cleanupOldExports($daysOld);
            $csvDeleted = $this->csvExportService->cleanupOldExports($daysOld);

            $totalDeleted = $pdfDeleted + $excelDeleted + $csvDeleted;

            return response()->json([
                'success' => true,
                'message' => "Deleted {$totalDeleted} old export files",
                'deleted' => [
                    'pdf' => $pdfDeleted,
                    'excel' => $excelDeleted,
                    'csv' => $csvDeleted,
                    'total' => $totalDeleted,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Cleanup failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
