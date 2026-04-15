<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\BankAccount;
use App\Models\BillPayment;
use App\Models\Bill;
use App\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class BillPaymentsController extends Controller
{
    public function __construct(
        private PostingEngineInterface $postingEngine,
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
            DB::beginTransaction();

            // Verify vendor exists
            $vendor = Vendor::findOrFail($validated['vendor_id']);

            // If bill_id provided, verify it belongs to the vendor and has balance
            if (!empty($validated['bill_id'])) {
                $bill = Bill::findOrFail($validated['bill_id']);
                
                if ($bill->vendor_id != $validated['vendor_id']) {
                    return response()->json([
                        'message' => 'Bill does not belong to the specified vendor',
                    ], 422);
                }

                if ($validated['amount'] > $bill->balance) {
                    return response()->json([
                        'message' => 'Payment amount exceeds bill balance',
                        'bill_balance' => $bill->balance,
                    ], 422);
                }
            }

            // Generate payment number
            $paymentNumber = BillPayment::generatePaymentNumber();

            // Create payment
            $payment = BillPayment::create([
                'payment_number'  => $paymentNumber,
                'vendor_id'       => $validated['vendor_id'],
                'bill_id'         => $validated['bill_id'] ?? null,
                'payment_date'    => $validated['payment_date'],
                'amount'          => $validated['amount'],
                'payment_method'  => $validated['payment_method'],
                'bank_account_id' => $validated['bank_account_id'] ?? null,
                'reference'       => $validated['reference'] ?? null,
                'memo'            => $validated['memo'] ?? null,
                'created_by'      => Auth::id(),
            ]);

            // Post to accounting ledger via PostingEngine
            // DR Accounts Payable, CR Cash/Bank (resolved by bank_account_id or method)
            $lines = [];

            // Resolve the disbursement GL account
            $bankAccountId   = $validated['bank_account_id'] ?? null;
            $bankGlAccountId = null;

            if ($bankAccountId) {
                $bankAcct        = BankAccount::find($bankAccountId);
                $bankGlAccountId = $bankAcct?->gl_account_id;
            }

            $cashMappingKey = match ($validated['payment_method']) {
                'bank'  => 'bank',
                default => 'cash',
            };

            $lines[] = new PostingRequestLine(
                accountMappingKey: 'accounts_payable',
                accountId: null,
                debit: (float) $validated['amount'],
                credit: 0,
                description: "Bill payment {$paymentNumber}",
            );

            $lines[] = new PostingRequestLine(
                accountMappingKey: $cashMappingKey,
                accountId: $bankGlAccountId,   // explicit GL account wins when bank account is set
                debit: 0,
                credit: (float) $validated['amount'],
                description: "Bill payment {$paymentNumber} - {$vendor->name}",
            );

            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'bill_payment',
                documentId: $payment->id,
                postingDate: $validated['payment_date'],
                description: "Bill Payment #{$paymentNumber} - {$vendor->name}",
                lines: $lines,
                reference: $paymentNumber,
                userId: Auth::id(),
            ));

            if (!$result->success) {
                throw new \Exception($result->error);
            }

            $payment->update(['journal_entry_id' => $result->journalEntryId]);

            // Update bill if bill_id provided using atomic operations
            if (!empty($validated['bill_id'])) {
                $bill = Bill::findOrFail($validated['bill_id']);
                Bill::where('id', $bill->id)->increment('paid_amount', (float) $validated['amount']);
                $bill->refresh();

                $newBalance = $bill->total - $bill->paid_amount;
                Bill::where('id', $bill->id)->update([
                    'balance' => $newBalance,
                    'status' => $newBalance <= 0 ? 'paid' : ($bill->paid_amount > 0 ? 'partial' : $bill->status),
                ]);

                // Update vendor spending total
                $vendor->updateSpending();
            }

            DB::commit();

            return response()->json([
                'message' => 'Payment recorded successfully',
                'data' => $payment->load(['vendor', 'bill']),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
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
            DB::beginTransaction();

            $oldAmount = $payment->amount;
            $payment->update($validated);

            // Update bill balance if amount changed and bill exists
            // Hardening (2026-04-15): Use atomic DB operations to prevent race conditions.
            if (isset($validated['amount']) && $payment->bill_id && $validated['amount'] != $oldAmount) {
                $amountDelta = $validated['amount'] - $oldAmount;
                Bill::where('id', $payment->bill_id)->increment('paid_amount', $amountDelta);
                $bill = Bill::findOrFail($payment->bill_id);
                $newBalance = $bill->total - $bill->paid_amount;

                Bill::where('id', $bill->id)->update([
                    'balance' => max($newBalance, 0),
                    'status' => $newBalance <= 0 ? 'paid' : ($bill->paid_amount > 0 ? 'partial' : ($bill->journal_entry_id ? 'posted' : 'draft')),
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Payment updated successfully',
                'data' => $payment->load(['vendor', 'bill']),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
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
            DB::beginTransaction();

            // Update bill balance if bill exists
            // Hardening (2026-04-15): Use atomic DB operations to prevent race conditions.
            if ($payment->bill_id) {
                Bill::where('id', $payment->bill_id)->decrement('paid_amount', (float) $payment->amount);
                $bill = Bill::findOrFail($payment->bill_id);
                $newBalance = $bill->total - $bill->paid_amount;

                Bill::where('id', $bill->id)->update([
                    'balance' => max($newBalance, 0),
                    'status' => $bill->paid_amount > 0 ? 'partial' : ($bill->journal_entry_id ? 'posted' : 'draft'),
                ]);
            }

            $payment->delete();

            DB::commit();

            return response()->json([
                'message' => 'Payment deleted successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'message' => 'Failed to delete payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
