<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteBankStatementReconciliationRequest;
use App\Http\Requests\ValidateBankStatementReconciliationRequest;
use App\Services\BankStatements\BankStatementReconciliationService;
use Illuminate\Http\JsonResponse;

class BankStatementReconciliationController extends Controller
{
    public function __construct(private BankStatementReconciliationService $service) {}

    public function validateReconciliation(ValidateBankStatementReconciliationRequest $request): JsonResponse
    {
        $data = $request->validated();

        return response()->json(['data' => $this->service->validate((int) $data['bank_account_id'], $data['import_ids'], (int) $request->user()->id)]);
    }

    public function complete(CompleteBankStatementReconciliationRequest $request): JsonResponse
    {
        $data = $request->validated();
        try {
            $reconciliation = $this->service->complete((int) $data['bank_account_id'], $data['import_ids'], $data['idempotency_key'], (int) $request->user()->id);

            return response()->json(['data' => $reconciliation], 201);
        } catch (\App\Services\BankStatements\MatchConflict $exception) {
            return response()->json(['code' => 'RECONCILIATION_CONFLICT', 'message' => $exception->getMessage()], 409);
        } catch (\DomainException $exception) {
            return response()->json(['code' => 'RECONCILIATION_BLOCKED', 'message' => $exception->getMessage()], 422);
        }
    }

    public function reverse(\Illuminate\Http\Request $request, int $reconciliationId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        try {
            return response()->json(['data' => $this->service->requestReversal($reconciliationId, $data['reason'], (int) $request->user()->id)], 201);
        } catch (\App\Services\BankStatements\MatchConflict $exception) {
            return response()->json(['code' => 'REVERSAL_CONFLICT', 'message' => $exception->getMessage()], 409);
        } catch (\DomainException $exception) {
            return response()->json(['code' => 'MAKER_CHECKER_REQUIRED', 'message' => $exception->getMessage()], 422);
        }
    }

    public function approveReversal(\Illuminate\Http\Request $request, string $reversalId): JsonResponse
    {
        try {
            return response()->json(['data' => $this->service->approveReversal($reversalId, (int) $request->user()->id)]);
        } catch (\App\Services\BankStatements\MatchConflict $exception) {
            return response()->json(['code' => 'REVERSAL_CONFLICT', 'message' => $exception->getMessage()], 409);
        } catch (\DomainException $exception) {
            return response()->json(['code' => 'MAKER_CHECKER_REQUIRED', 'message' => $exception->getMessage()], 422);
        }
    }
}
