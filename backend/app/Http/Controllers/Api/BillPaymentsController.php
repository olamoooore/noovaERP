<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BillPayment;
use App\Services\BillPaymentService;
use App\Services\LockDateValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BillPaymentsController extends Controller
{
    public function __construct(
        private BillPaymentService $billPaymentService,
        private LockDateValidationService $lockDateValidator,
    ) {}
    /**
     * Display a listing of bill payments
     */
    public function index(Request $request)
    {
        $query = BillPayment::with(['vendor', 'bill', 'journalEntry']);

        // Filter by vendor
        if ($request->has('vendor_id')) {
            $query->where('vendor_id', $request->vendor_id);
        }

        // Filter by bill
        if ($request->has('bill_id')) {
            $query->where('bill_id', $request->bill_id);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('payment_number', 'like', "%{$search}%")
                  ->orWhere('reference', 'like', "%{$search}%")
                  ->orWhereHas('vendor', function ($vq) use ($search) {
                      $vq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        // Date range filter
        if ($request->has('start_date')) {
            $query->where('payment_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('payment_date', '<=', $request->end_date);
        }

        $perPage = $request->get('per_page', 20);
        $payments = $query->orderBy('payment_date', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json($payments);
    }

    /**
     * Store a newly created bill payment
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'vendor_id'       => 'required|exists:vendors,id',
            'bill_id'         => 'nullable|exists:bills,id',
            'payment_date'    => 'required|date',
            'amount'          => 'required|numeric|min:0.01',
            'payment_method'  => 'required|in:cash,bank,card,check,other',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
            'reference'       => 'nullable|string|max:255',
            'memo'            => 'nullable|string',
        ]);

        try {
            // Validate bank balance if paying from a bank account
            if (!empty($validated['bank_account_id'])) {
                $bankAccount = \App\Models\BankAccount::find($validated['bank_account_id']);
                if ($bankAccount) {
                    $currentBalance = $bankAccount->getCurrentBalance();
                    if ($validated['amount'] > $currentBalance) {
                        return response()->json([
                            'message' => 'Payment amount exceeds bank account balance',
                            'bank_balance' => $currentBalance,
                            'requested_amount' => $validated['amount'],
                        ], 422);
                    }
                }
            }

            $data = array_merge($validated, ['created_by' => Auth::id()]);

            // Lock-date check: block recording bill payment dated in a closed period
            $paymentDate = $validated['payment_date'] ?? now()->toDateString();
            $lockResult = $this->lockDateValidator->validateTransactionDate('payments', $paymentDate);
            if ($lockResult['is_locked']) {
                return response()->json([
                    'message' => "Cannot record bill payment dated in a closed period. {$lockResult['message']}",
                    'lock_date' => $lockResult['lock_date'],
                    'transaction_date' => $paymentDate,
                ], 422);
            }

            $payment = $this->billPaymentService->recordPayment($data, Auth::id());

            return response()->json([
                'message' => 'Payment recorded successfully',
                'data' => $payment->load(['vendor', 'bill']),
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to record payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified bill payment
     */
    public function show($id)
    {
        $payment = BillPayment::with(['vendor', 'bill', 'journalEntry', 'createdBy'])
            ->findOrFail($id);

        return response()->json([
            'data' => $payment,
        ]);
    }

    /**
     * Update the specified bill payment
     */
    public function update(Request $request, $id)
    {
        $payment = BillPayment::findOrFail($id);

        // Prevent updates if journal entry exists
        if ($payment->journal_entry_id) {
            return response()->json([
                'message' => 'Cannot update payment that has been posted to journal',
            ], 403);
        }

        $validated = $request->validate([
            'payment_date' => 'sometimes|date',
            'amount' => 'sometimes|numeric|min:0.01',
            'payment_method' => 'sometimes|in:cash,bank,card,check,other',
            'reference' => 'nullable|string|max:255',
            'memo' => 'nullable|string',
        ]);

        try {
            $payment = $this->billPaymentService->updateDraftPayment($payment, $validated);

            return response()->json([
                'message' => 'Payment updated successfully',
                'data' => $payment->load(['vendor', 'bill']),
            ]);

        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 403);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to update payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified bill payment
     */
    public function destroy($id)
    {
        $payment = BillPayment::findOrFail($id);

        // Prevent deletion if journal entry exists
        if ($payment->journal_entry_id) {
            return response()->json([
                'message' => 'Cannot delete payment that has been posted to journal',
            ], 403);
        }

        try {
            $this->billPaymentService->deleteDraftPayment($payment);

            return response()->json([
                'message' => 'Payment deleted successfully',
            ]);

        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 403);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
