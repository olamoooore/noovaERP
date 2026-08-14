<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Accounting\AccountingImportBatch;
use App\Services\AccountingImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingImportController extends Controller
{
    public function __construct(
        private AccountingImportService $importService
    ) {}

    /**
     * Stage a CSV file for import.
     */
    public function stage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:10240',
            'import_type' => 'required|string|in:bill,invoice,journal_entry,payment',
            'mapping' => 'nullable|array',
        ]);

        $file = $request->file('file');
        $rows = $this->parseCsv($file);

        $batch = $this->importService->stageBatch(
            $rows,
            $validated['import_type'],
            $file->getClientOriginalName(),
            hash_file('sha256', $file->getRealPath()),
            $request->user()->id,
            $validated['mapping'] ?? null
        );

        return $this->success([
            'batch_id' => $batch->id,
            'total_rows' => $batch->total_rows,
            'valid_rows' => $batch->valid_rows,
            'error_rows' => $batch->error_rows,
            'status' => $batch->status,
        ], 'Import staged.', 201);
    }

    /**
     * Get batch status.
     */
    public function status(string $batchId): JsonResponse
    {
        $batch = $this->importService->getBatchStatus($batchId);

        return $this->success($batch, 'Batch status retrieved.');
    }

    /**
     * Commit a validated batch.
     */
    public function commit(Request $request, string $batchId): JsonResponse
    {
        $result = $this->importService->commitBatch($batchId, $request->user()->id);

        return $this->success($result, 'Import committed.');
    }

    /**
     * Parse CSV file into array of rows.
     */
    private function parseCsv($file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $headers = fgetcsv($handle);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_combine($headers, $row);
        }

        fclose($handle);

        return $rows;
    }
}
