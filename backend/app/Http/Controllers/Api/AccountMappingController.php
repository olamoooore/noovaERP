<?php

namespace App\Http\Controllers\Api;

use App\Accounting\Services\AccountMappingService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * #775 (AU2): read-only account-role mapping health, plus the one-click "map"
 * fix. Confirmation UX lives in the settings card; the write here is explicit.
 */
class AccountMappingController extends Controller
{
    public function __construct(
        private readonly AccountMappingService $mappingService,
    ) {}

    public function health(): JsonResponse
    {
        return response()->json(['data' => $this->mappingService->health()]);
    }

    public function update(Request $request, string $mappingKey): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => 'required|integer|exists:chart_of_accounts,id',
        ]);

        $mapping = $this->mappingService->map($mappingKey, (int) $validated['account_id']);

        return response()->json([
            'data' => [
                'mapping_key' => $mapping->mapping_key,
                'account_id' => (int) $mapping->account_id,
            ],
        ]);
    }
}
