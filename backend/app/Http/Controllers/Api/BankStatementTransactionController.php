<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankStatementTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankStatementTransactionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = BankStatementTransaction::with(['allocations', 'matches'])->orderBy('transaction_date')->orderBy('source_row');
        if ($request->filled('bank_account_id')) {
            $query->where('bank_account_id', $request->integer('bank_account_id'));
        }
        if ($request->filled('import_id')) {
            $query->where('import_id', $request->string('import_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($request->filled('search')) {
            $query->where(fn ($q) => $q->where('description', 'ILIKE', '%'.$request->string('search').'%')->orWhere('bank_reference', 'ILIKE', '%'.$request->string('search').'%'));
        }

        return response()->json($query->paginate(min(250, max(1, $request->integer('per_page', 50)))));
    }

    public function show(string $transactionId): JsonResponse
    {
        return response()->json(['data' => BankStatementTransaction::with(['import', 'allocations', 'matches'])->findOrFail($transactionId)]);
    }
}
