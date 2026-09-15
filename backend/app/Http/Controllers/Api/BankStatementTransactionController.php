<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankStatementTransaction;
use App\Services\BankStatements\BankStatementAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankStatementTransactionController extends Controller
{
    public function __construct(private BankStatementAuditService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $query = BankStatementTransaction::with(['allocations', 'adjustments', 'matches.reconciliation:id,statement_date,status'])->orderBy('transaction_date')->orderBy('source_row');
        if ($request->filled('bank_account_id')) {
            $query->where('bank_account_id', $request->integer('bank_account_id'));
        }
        if ($request->filled('import_id')) {
            $query->where('import_id', $request->string('import_id'));
        }
        if ($request->filled('transaction_ids')) {
            $transactionIds = $request->input('transaction_ids');
            $transactionIds = is_array($transactionIds) ? $transactionIds : explode(',', (string) $transactionIds);
            $query->whereIn('id', array_values(array_filter($transactionIds)));
        }
        if ($request->filled('status')) {
            if ($request->string('status')->toString() === 'reconciled') {
                $query->whereHas('matches', fn ($matchQuery) => $matchQuery->where('status', 'accepted')->whereNotNull('reconciliation_id'));
            } elseif ($request->string('status')->toString() === 'unreconciled') {
                $query->whereDoesntHave('matches', fn ($matchQuery) => $matchQuery->where('status', 'accepted')->whereNotNull('reconciliation_id'));
            } elseif ($request->string('status')->toString() !== 'all') {
                $query->where('status', $request->string('status')->toString());
            }
        }
        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(fn ($q) => $q->where('description', 'ILIKE', '%'.$search.'%')->orWhere('bank_reference', 'ILIKE', '%'.$search.'%')->orWhereHas('allocations', fn ($allocationQuery) => $allocationQuery->where('external_reference', 'ILIKE', '%'.$search.'%')));
        }

        return response()->json($query->paginate(min(250, max(1, $request->integer('per_page', 50)))));
    }

    public function show(string $transactionId): JsonResponse
    {
        return response()->json(['data' => BankStatementTransaction::with(['import', 'allocations', 'adjustments', 'matches.reconciliation:id,statement_date,status'])->findOrFail($transactionId)]);
    }

    public function exclude(Request $request, string $transactionId): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:duplicate,non_transaction'],
            'reason' => ['required', 'string', 'max:2000'],
            'evidence' => ['required', 'array', 'min:1'],
        ]);

        $transaction = DB::transaction(function () use ($transactionId, $data, $request) {
            $transaction = BankStatementTransaction::with('matches')->lockForUpdate()->findOrFail($transactionId);
            if (in_array($transaction->status, ['matched', 'partially_matched', 'reversed'], true)
                || $transaction->matches->contains('status', 'accepted')) {
                abort(409, 'Matched, partially matched, or reversed statement transactions cannot be excluded.');
            }
            if ($transaction->status === 'excluded') {
                return $transaction;
            }

            $before = ['status' => $transaction->status];
            $transaction->update([
                'status' => 'excluded',
                'exclusion_type' => $data['type'],
                'exclusion_reason' => $data['reason'],
                'exclusion_evidence' => $data['evidence'],
                'excluded_by' => $request->user()->id,
                'excluded_at' => now(),
            ]);
            $this->audit->record('statement_transaction_excluded', [
                'actor_id' => $request->user()->id,
                'bank_account_id' => $transaction->bank_account_id,
                'import_id' => $transaction->import_id,
                'statement_transaction_id' => $transaction->id,
                'reason' => $data['reason'],
                'before_state' => $before,
                'after_state' => ['status' => 'excluded', 'type' => $data['type']],
                'metadata' => ['evidence_keys' => array_keys($data['evidence'])],
            ]);

            return $transaction->fresh();
        });

        return response()->json(['data' => $transaction]);
    }

    public function restore(Request $request, string $transactionId): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $transaction = DB::transaction(function () use ($transactionId, $data, $request) {
            $transaction = BankStatementTransaction::lockForUpdate()->findOrFail($transactionId);
            if ($transaction->status !== 'excluded') {
                abort(409, 'Only excluded statement transactions can be restored.');
            }
            $before = ['status' => 'excluded', 'type' => $transaction->exclusion_type, 'reason' => $transaction->exclusion_reason];
            $transaction->update(['status' => 'unmatched', 'exclusion_type' => null, 'exclusion_reason' => null, 'exclusion_evidence' => null, 'excluded_by' => null, 'excluded_at' => null]);
            $this->audit->record('statement_transaction_restored', [
                'actor_id' => $request->user()->id,
                'bank_account_id' => $transaction->bank_account_id,
                'import_id' => $transaction->import_id,
                'statement_transaction_id' => $transaction->id,
                'reason' => $data['reason'],
                'before_state' => $before,
                'after_state' => ['status' => 'unmatched'],
            ]);

            return $transaction->fresh();
        });

        return response()->json(['data' => $transaction]);
    }
}
