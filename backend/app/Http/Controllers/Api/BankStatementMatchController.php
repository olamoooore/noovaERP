<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateBankStatementMatchRequest;
use App\Services\BankStatements\BankStatementMatchingService;
use App\Services\BankStatements\MatchConflict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BankStatementMatchController extends Controller
{
    public function __construct(private BankStatementMatchingService $service) {}

    public function suggest(Request $request, string $importId): JsonResponse
    {
        try {
            return response()->json(['data' => $this->service->suggest($importId, (int) $request->user()->id), 'correlation_id' => (string) Str::uuid()]);
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

    public function destroy(Request $request, string $matchId): JsonResponse
    {
        try {
            $this->service->remove($matchId, (int) $request->user()->id);

            return response()->json(['message' => 'Statement match removed.', 'correlation_id' => (string) Str::uuid()]);
        } catch (MatchConflict $e) {
            return response()->json(['code' => 'MATCH_CONFLICT', 'message' => $e->getMessage(), 'correlation_id' => (string) Str::uuid()], 409);
        }
    }

    public function storeGroup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:80'], 'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.bank_statement_transaction_id' => ['required', 'uuid', 'exists:bank_statement_transactions,id'],
            'allocations.*.journal_entry_line_id' => ['required', 'integer', 'exists:journal_entry_lines,id'],
            'allocations.*.matched_amount' => ['required', 'numeric', 'not_in:0'], 'allocations.*.review_reason' => ['required', 'string', 'max:1000'],
        ]);
        try {
            return response()->json(['data' => $this->service->acceptGroup($data['allocations'], $data['idempotency_key'], (int) $request->user()->id)], 201);
        } catch (MatchConflict $exception) {
            return response()->json(['code' => 'MATCH_CONFLICT', 'message' => $exception->getMessage()], 409);
        } catch (\DomainException $exception) {
            return response()->json(['code' => 'INVALID_GROUP_MATCH', 'message' => $exception->getMessage()], 422);
        }
    }

    public function bulkAccept(Request $request): JsonResponse
    {
        $data = $request->validate(['import_id' => ['required', 'uuid', 'exists:bank_statement_imports,id'], 'idempotency_key' => ['required', 'string', 'max:80']]);
        try {
            return response()->json(['data' => $this->service->bulkAccept($data['import_id'], $data['idempotency_key'], (int) $request->user()->id)]);
        } catch (MatchConflict $exception) {
            return response()->json(['code' => 'MATCH_CONFLICT', 'message' => $exception->getMessage()], 409);
        }
    }
}
