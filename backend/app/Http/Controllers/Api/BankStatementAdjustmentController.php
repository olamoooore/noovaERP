<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BankStatements\BankStatementAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankStatementAdjustmentController extends Controller
{
    public function __construct(private BankStatementAdjustmentService $service) {}

    public function store(Request $request, string $transactionId): JsonResponse
    {
        $data = $request->validate(['contra_account_id' => ['required', 'integer', 'exists:chart_of_accounts,id'], 'amount' => ['required', 'numeric', 'not_in:0'], 'description' => ['required', 'string', 'max:255'], 'reason' => ['required', 'string', 'max:2000'], 'idempotency_key' => ['required', 'string', 'max:100']]);
        try {
            return response()->json(['data' => $this->service->propose($transactionId, $data, (int) $request->user()->id)], 201);
        } catch (\DomainException $exception) {
            return response()->json(['code' => 'ADJUSTMENT_REJECTED', 'message' => $exception->getMessage()], 422);
        }
    }

    public function approve(Request $request, string $adjustmentId): JsonResponse
    {
        try {
            return response()->json(['data' => $this->service->approve($adjustmentId, (int) $request->user()->id)]);
        } catch (\DomainException $exception) {
            return response()->json(['code' => 'ADJUSTMENT_APPROVAL_REJECTED', 'message' => $exception->getMessage()], 422);
        }
    }
}
