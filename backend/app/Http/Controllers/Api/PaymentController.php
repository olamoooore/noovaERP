<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoiceService;
use App\Services\LockDateValidationService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private InvoiceService $invoiceService,
        private LockDateValidationService $lockDateValidator,
    ) {}

    /**
     * List payments.
     */
    public function index(Request $request)
    {
        $query = Payment::with(['customer', 'invoice', 'journalEntry'])
            ->orderBy('payment_date', 'desc');

        if ($request->has('invoice_id')) {
            $query->where('invoice_id', $request->invoice_id);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('payment_number', 'ILIKE', "%{$search}%")
                    ->orWhere('reference', 'ILIKE', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('name', 'ILIKE', "%{$search}%");
                    });
            });
        }

        $payments = $query->paginate($request->per_page ?? 20);

        return response()->json($payments);
    }

    /**
     * Get eligible invoices for recording a customer payment.
     * Only posted, sent, or partially paid invoices with a positive balance are eligible.
     */
    public function eligibleInvoices(Request $request)
    {
        $request->validate([
            'customer_id' => 'nullable|integer|exists:customers,id',
        ]);

        $query = \App\Models\Invoice::with('customer')
            ->whereIn('status', ['posted', 'sent', 'partial'])
            ->where(function ($q) {
                $q->whereNull('balance')
                    ->orWhere('balance', '>', 0);
            })
            ->orderBy('invoice_date', 'desc');

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        $invoices = $query->get()->map(function ($invoice) {
            return [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => $invoice->invoice_date,
                'total' => (float) $invoice->total,
                'balance' => (float) ($invoice->balance ?? $invoice->total),
                'status' => $invoice->status,
                'customer_id' => $invoice->customer_id,
                'customer_name' => $invoice->customer?->name ?? 'Unknown',
            ];
        });

        if ($invoices->isEmpty()) {
            return response()->json([
                'data' => [],
                'message' => 'No eligible invoices found. Only posted unpaid invoices for the selected customer can be paid.',
            ]);
        }

        return response()->json([
            'data' => $invoices,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'payment_date' => 'nullable|date',
            'amount' => 'required|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'method' => 'required|in:cash,card,bank_transfer,check,other',
            'reference' => 'nullable|string',
            'notes' => 'nullable|string',
            'bank_account_id' => 'nullable|exists:bank_accounts,id',
        ]);

        try {
            $invoice = Invoice::findOrFail($validated['invoice_id']);
            $discountAmount = $validated['discount_amount'] ?? 0;
            $totalApplied = $validated['amount'] + $discountAmount;

            if ($totalApplied > $invoice->balance) {
                return response()->json([
                    'message' => 'Payment amount exceeds invoice balance',
                ], 422);
            }

            // Lock-date check: block recording payment dated in a closed period
            $paymentDate = $validated['payment_date'] ?? now()->toDateString();
            $lockResult = $this->lockDateValidator->validateTransactionDate('payments', $paymentDate);
            if ($lockResult['is_locked']) {
                return response()->json([
                    'message' => "Cannot record payment dated in a closed period. {$lockResult['message']}",
                    'lock_date' => $lockResult['lock_date'],
                    'transaction_date' => $paymentDate,
                ], 422);
            }

            // Validate bank balance if paying from a bank account
            if (! empty($validated['bank_account_id'])) {
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

            $payment = $this->invoiceService->recordPayment($invoice, $validated);

            return response()->json([
                'message' => 'Payment recorded successfully',
                'id' => $payment->id,
                'data' => $payment,
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to record payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($payment)
    {
        $payment = Payment::findOrFail($payment);

        return response()->json($payment->load(['customer', 'invoice', 'journalEntry']));
    }

    public function destroy($payment)
    {
        $payment = Payment::findOrFail($payment);

        return response()->json(['message' => 'Payments cannot be deleted once posted. Use the cancel endpoint instead.'], 403);
    }

    /**
     * Cancel a recorded payment (recorded → cancelled).
     * Unapplies the payment from the linked invoice, restoring the invoice balance.
     * The payment record is preserved for audit but marked as cancelled.
     */
    public function cancel($paymentId)
    {
        $payment = Payment::findOrFail($paymentId);

        if ($payment->payment_status !== 'recorded') {
            return response()->json(['message' => 'Only recorded payments can be cancelled'], 403);
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($payment) {
                // Unapply from linked invoice if one exists
                if ($payment->invoice_id) {
                    $invoice = Invoice::find($payment->invoice_id);
                    if ($invoice) {
                        $invoice->paid_amount = max(0, (float) $invoice->paid_amount - (float) $payment->amount);
                        $invoice->balance = $invoice->total - $invoice->paid_amount;

                        // Update invoice status based on new balance
                        if ($invoice->paid_amount <= 0) {
                            $invoice->status = $invoice->journal_entry_id ? 'posted' : 'draft';
                            $invoice->settlement_status = 'unpaid';
                        } else {
                            $invoice->status = 'partial';
                            $invoice->settlement_status = 'partial';
                        }
                        $invoice->save();
                    }
                }

                $payment->update([
                    'payment_status' => 'cancelled',
                    'invoice_id' => null,
                ]);
            });

            return response()->json([
                'message' => 'Payment cancelled and unapplied from invoice',
                'data' => $payment->fresh(['customer', 'invoice']),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to cancel payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
