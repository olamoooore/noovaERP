<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DocumentTraceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Document tracing: any reference (PO / Customer Ref. / Internal Ref. /
 * project or document number) resolves to the whole related document family
 * (quotation → sales order → delivery order → invoice).
 */
class ReferenceTraceController extends Controller
{
    public function __construct(private readonly DocumentTraceService $traceService) {}

    public function trace(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'required|string|min:2|max:120',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        return response()->json(
            $this->traceService->trace($validated['q'], (int) ($validated['limit'] ?? 25))
        );
    }
}
