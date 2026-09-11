<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateBankStatementMatchRequest;
use App\Services\BankStatements\BankStatementMatchingService;
use App\Services\BankStatements\MatchConflict;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class BankStatementMatchController extends Controller
{
    public function __construct(private BankStatementMatchingService $service) {}

    public function suggest(string $importId): JsonResponse
    {
        try {
            return response()->json(['data' => $this->service->suggest($importId), 'correlation_id' => (string) Str::uuid()]);
        } catch (\DomainException $e) {
            return response()->json(['code' => 'MATCH_SUGGESTION_REJECTED', 'message' => $e->getMessage(), 'correlation_id' => (string) Str::uuid()], 422);
        }
    }

    public function store(CreateBankStatementMatchRequest $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->service->accept($request->validated(), (int) $request->user()->id), 'correlation_id' => (string) Str::uuid()], 201);
        } catch (MatchConflict $e) {
            return response()->json(['code' => 'MATCH_CONFLICT', 'message' => $e->getMessage(), 'correlation_id' => (string) Str::uuid()], 409);
        } catch (\DomainException $e) {
            return response()->json(['code' => 'INVALID_MATCH', 'message' => $e->getMessage(), 'correlation_id' => (string) Str::uuid()], 422);
        }
    }

    public function destroy(string $matchId): JsonResponse
    {
        try {
            $this->service->remove($matchId);

            return response()->json(['message' => 'Statement match removed.', 'correlation_id' => (string) Str::uuid()]);
        } catch (MatchConflict $e) {
            return response()->json(['code' => 'MATCH_CONFLICT', 'message' => $e->getMessage(), 'correlation_id' => (string) Str::uuid()], 409);
        }
    }
}
