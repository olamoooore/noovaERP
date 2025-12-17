<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private InvoiceService $invoiceService
    ) {}

    public function index()
    {
        $payments = Payment::with(['customer', 'invoice', 'journalEntry'])
            ->orderBy('payment_date', 'desc')
            ->paginate(20);

        return response()->json($payments);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'payment_date' => 'nullable|date',
            'amount' => 'required|numeric|min:0',
            'method' => 'required|in:cash,card,bank_transfer,check,other',
            'reference' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        try {
            $invoice = Invoice::findOrFail($validated['invoice_id']);
            
            if ($validated['amount'] > $invoice->balance) {
                return response()->json([
                    'message' => 'Payment amount exceeds invoice balance',
                ], 422);
            }

            $payment = $this->invoiceService->recordPayment($invoice, $validated);

            return response()->json($payment, 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to record payment',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show(Payment $payment)
    {
        return response()->json($payment->load(['customer', 'invoice', 'journalEntry']));
    }

    public function destroy(Payment $payment)
    {
        return response()->json(['message' => 'Payments cannot be deleted once posted'], 403);
    }
}
