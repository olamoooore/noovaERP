<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PaymentImportService;
use Illuminate\Http\Request;

class PaymentImportController extends Controller
{
    public function __construct(
        private PaymentImportService $importService,
    ) {}

    /**
     * Import customer payments from a CSV file.
     *
     * POST /api/v1/payments/import
     */
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
        ]);

        try {
            $result = $this->importService->importFromFile($request->file('file'));

            return response()->json($result, 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Payment import failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Import failed: '.$e->getMessage(),
            ], 500);
        }
    }
}
