<?php

namespace Modules\PaymentGateway\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentGatewayFactory;
use App\Services\AccountingService;

class PaymentController extends Controller
{
    /**
     * Create a new payment
     */
    public function create(Request $request)
    {
        $validated = $request->validate([
            'gateway_id' => 'required|exists:payment_gateways,id',
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'sometimes|string|size:3',
            'email' => 'sometimes|email',
            'redirect_url' => 'sometimes|url',
        ]);

        $gateway = PaymentGateway::findOrFail($validated['gateway_id']);
        $invoice = Invoice::findOrFail($validated['invoice_id']);

        if (!$gateway->is_active) {
            return response()->json([
                'message' => 'Payment gateway is not active',
            ], 422);
        }

        try {
            $service = PaymentGatewayFactory::create($gateway);

            // Determine currency: priority is validated > invoice > gateway defaults
            $currency = $validated['currency'] ?? $invoice->currency ?? null;
            if (!$currency) {
                // Default currencies by gateway
                $currency = match ($gateway->gateway_name) {
                    'hitpay' => 'MYR',
                    'paypal' => 'MYR',
                    'stripe' => 'MYR',
                    default => 'MYR',
                };
            }

            $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
            $paymentData = [
                'amount' => $validated['amount'],
                'currency' => $currency,
                'email' => $validated['email'] ?? $invoice->customer->email,
                'description' => "Payment for Invoice {$invoice->invoice_number}",
                'reference' => $invoice->invoice_number,
                'redirect_url' => $frontendUrl . '/payment/success',
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'customer_id' => $invoice->customer_id,
                ],
            ];

            \Log::info('Creating payment with currency', [
                'invoice_id' => $invoice->id,
                'invoice_currency' => $invoice->currency,
                'payment_currency' => $currency,
                'gateway' => $gateway->gateway_name,
            ]);

            $result = $service->createPayment($paymentData);

            if ($result['success']) {
                // Clean up old pending transactions for this invoice (older than 30 minutes)
                PaymentTransaction::where('invoice_id', $invoice->id)
                    ->where('status', 'pending')
                    ->where('created_at', '<', now()->subMinutes(30))
                    ->delete();

                // Merge metadata from gateway response (e.g., approve_link for PayPal)
                $transactionMetadata = array_merge(
                    $paymentData['metadata'],
                    $result['metadata'] ?? []
                );

                // Use firstOrCreate to handle idempotency atomically
                $transaction = PaymentTransaction::firstOrCreate(
                    ['transaction_id' => $result['transaction_id']], // Find by this
                    [ // Create with these values if not found
                        'payment_gateway_id' => $gateway->id,
                        'invoice_id' => $invoice->id,
                        'customer_id' => $invoice->customer_id,
                        'amount' => $validated['amount'],
                        'currency' => $currency,
                        'status' => 'pending',
                        'metadata' => $transactionMetadata,
                    ]
                );

                if ($transaction->wasRecentlyCreated) {
                    \Log::info('Created new payment transaction', [
                        'transaction_id' => $transaction->transaction_id,
                        'invoice_id' => $invoice->id,
                        'metadata' => $transactionMetadata,
                    ]);
                } else {
                    \Log::info('Returning existing payment transaction (idempotency)', [
                        'transaction_id' => $transaction->transaction_id,
                        'invoice_id' => $invoice->id,
                    ]);
                }

                return response()->json([
                    'message' => 'Payment initiated successfully',
                    'transaction' => $transaction,
                    'payment_url' => $result['payment_url'] ?? null,
                    'client_secret' => $result['client_secret'] ?? null,
                    'publishable_key' => $gateway->publishable_key ?? null,
                    'invoice' => $invoice->load('customer'),
                ], 201);
            }

            return response()->json([
                'message' => 'Payment creation failed',
                'error' => $result['error'] ?? 'Unknown error',
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Payment creation failed', [
                'invoice_id' => $validated['invoice_id'],
                'gateway_id' => $validated['gateway_id'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Provide user-friendly error messages
            $errorMessage = 'Unable to process payment. ';

            if (str_contains($e->getMessage(), 'api_key')) {
                $errorMessage .= 'Payment gateway is not properly configured. Please contact support.';
            } elseif (str_contains($e->getMessage(), 'network') || str_contains($e->getMessage(), 'timeout')) {
                $errorMessage .= 'Network connection failed. Please try again.';
            } elseif (str_contains($e->getMessage(), 'amount')) {
                $errorMessage .= 'Invalid payment amount. Please check the invoice total.';
            } else {
                $errorMessage .= 'Please try again or contact support if the problem persists.';
            }

            return response()->json([
                'message' => $errorMessage,
                'error_code' => 'PAYMENT_CREATION_FAILED',
                'technical_details' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Confirm payment - queries payment gateway API for current status
     * Note: In production, rely on webhooks for automatic updates
     */
    public function confirm(Request $request)
    {
        $validated = $request->validate([
            'transaction_id' => 'required|string',
        ]);

        $transaction = PaymentTransaction::where('transaction_id', $validated['transaction_id'])->firstOrFail();
        $gateway = $transaction->gateway;

        \Log::info('Payment confirmation requested', [
            'transaction_id' => $validated['transaction_id'],
            'current_status' => $transaction->status,
            'gateway' => $gateway->gateway_name,
        ]);

        try {
            // Query payment gateway API for current status
            $service = PaymentGatewayFactory::create($gateway);

            // For PayPal, we need to capture the order after approval
            if ($gateway->gateway_name === 'paypal' && $transaction->status === 'pending') {
                \Log::info('Capturing PayPal order', [
                    'transaction_id' => $transaction->transaction_id,
                ]);

                $captureResult = $service->capturePayment($transaction->transaction_id);

                if ($captureResult['success']) {
                    $transaction->update([
                        'status' => $captureResult['status'],
                        'payment_method' => $captureResult['payment_method'] ?? null,
                        'gateway_response' => $captureResult,
                        'metadata' => array_merge(
                            $transaction->metadata ?? [],
                            $captureResult['metadata'] ?? []
                        ),
                    ]);

                    \Log::info('PayPal payment captured successfully', [
                        'transaction_id' => $transaction->transaction_id,
                        'status' => $captureResult['status'],
                    ]);

                    // If payment completed, create Payment record and update invoice
                    if (in_array($captureResult['status'], ['completed', 'succeeded'])) {
                        \Log::info('Processing completed PayPal payment', [
                            'transaction_id' => $transaction->transaction_id,
                        ]);
                        $this->processCompletedPayment($transaction);
                    }

                    return response()->json([
                        'message' => 'Payment captured successfully',
                        'transaction' => $transaction->fresh(),
                        'invoice' => $transaction->invoice->fresh(),
                    ]);
                } else {
                    \Log::error('Failed to capture PayPal payment', [
                        'transaction_id' => $transaction->transaction_id,
                        'error' => $captureResult['error'] ?? 'Unknown error',
                    ]);

                    return response()->json([
                        'message' => 'Failed to capture payment',
                        'error' => $captureResult['error'] ?? 'Payment capture failed',
                    ], 422);
                }
            }

            // For other gateways (Stripe, HitPay), just get status
            $result = $service->getPaymentStatus($transaction->transaction_id);

            \Log::info('Gateway payment status result', [
                'transaction_id' => $transaction->transaction_id,
                'result' => $result,
            ]);

            if ($result['success']) {
                $transaction->update([
                    'status' => $result['status'],
                    'payment_method' => $result['payment_method'] ?? null,
                    'gateway_response' => $result,
                ]);

                \Log::info('Payment status updated', [
                    'transaction_id' => $transaction->transaction_id,
                    'status' => $result['status'],
                    'gateway' => $gateway->gateway_name,
                ]);

                // If payment completed, create Payment record and update invoice
                // Check for both 'completed' (HitPay/generic) and 'succeeded' (Stripe original status)
                if (in_array($result['status'], ['completed', 'succeeded'])) {
                    \Log::info('Processing completed payment', [
                        'transaction_id' => $transaction->transaction_id,
                    ]);
                    $this->processCompletedPayment($transaction);
                }

                return response()->json([
                    'message' => 'Payment status updated',
                    'transaction' => $transaction->fresh(),
                    'invoice' => $transaction->invoice->fresh(),
                ]);
            }

            \Log::warning('Failed to retrieve payment status from gateway', [
                'transaction_id' => $transaction->transaction_id,
                'result' => $result,
            ]);

            return response()->json([
                'message' => 'Failed to retrieve payment status',
                'details' => $result,
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Error confirming payment', [
                'transaction_id' => $validated['transaction_id'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Error confirming payment: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get payment status
     */
    public function status(PaymentTransaction $transaction)
    {
        return response()->json($transaction->load(['gateway', 'invoice', 'customer']));
    }

    /**
     * List all payment transactions
     */
    public function index(Request $request)
    {
        $query = PaymentTransaction::with(['gateway', 'invoice', 'customer'])
            ->orderBy('created_at', 'desc');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('gateway_id')) {
            $query->where('payment_gateway_id', $request->gateway_id);
        }

        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        $transactions = $query->paginate(20);

        return response()->json($transactions);
    }

    /**
     * Process completed payment (internal helper)
     */
    protected function processCompletedPayment(PaymentTransaction $transaction)
    {
        DB::transaction(function () use ($transaction) {
            $invoice = $transaction->invoice;
            $invoice->refresh();

            // Check if payment already processed for this transaction
            $existingPayment = Payment::where('reference', $transaction->transaction_id)->first();

            if ($existingPayment) {
                \Log::info('Payment already processed, updating invoice only', [
                    'transaction_id' => $transaction->transaction_id,
                    'payment_id' => $existingPayment->id,
                    'invoice_id' => $invoice->id,
                ]);

                // Payment exists, ensure invoice reflects the payment
                $this->updateInvoiceFromPayment($invoice, $transaction->amount);

                // Mark transaction as processed
                if (!$transaction->processed_at) {
                    $transaction->update(['processed_at' => now()]);
                }

                return;
            }

            // Create new Payment record
            try {
                $payment = Payment::create([
                    'customer_id' => $transaction->customer_id,
                    'invoice_id' => $invoice->id,
                    'payment_date' => now(),
                    'method' => 'card',
                    'reference' => $transaction->transaction_id,
                    'amount' => $transaction->amount,
                    'notes' => "Online payment via {$transaction->gateway->gateway_name}",
                    'received_by' => auth()->id() ?? $invoice->created_by,
                ]);

                \Log::info('Payment created successfully', [
                    'payment_id' => $payment->id,
                    'payment_number' => $payment->payment_number,
                    'transaction_id' => $transaction->transaction_id,
                    'amount' => $transaction->amount,
                ]);
            } catch (\Exception $e) {
                \Log::error('Failed to create payment record', [
                    'transaction_id' => $transaction->transaction_id,
                    'error' => $e->getMessage(),
                ]);

                // Continue to update invoice even if payment creation fails
            }

            // Update invoice with payment
            $this->updateInvoiceFromPayment($invoice, $transaction->amount);

            // Mark transaction as processed
            $transaction->update(['processed_at' => now()]);

            \Log::info('Payment processing completed', [
                'transaction_id' => $transaction->transaction_id,
                'invoice_id' => $invoice->id,
                'invoice_status' => $invoice->status,
                'paid_amount' => $invoice->paid_amount,
                'balance' => $invoice->balance,
            ]);
        });
    }

    /**
     * Update invoice with payment amount and status
     */
    protected function updateInvoiceFromPayment($invoice, $amount)
    {
        // Calculate new amounts
        $newPaidAmount = $invoice->paid_amount + $amount;
        $newBalance = $invoice->total - $newPaidAmount;

        // Update invoice
        $invoice->paid_amount = $newPaidAmount;
        $invoice->balance = $newBalance;

        // Update status based on balance
        if ($newBalance <= 0) {
            $invoice->status = 'paid';
        } elseif ($newPaidAmount > 0 && $invoice->status !== 'paid') {
            $invoice->status = 'partial';
        }

        $invoice->save();

        \Log::info('Invoice updated', [
            'invoice_id' => $invoice->id,
            'status' => $invoice->status,
            'paid_amount' => $invoice->paid_amount,
            'balance' => $invoice->balance,
        ]);
    }

    /**
     * Get transaction by reference (for payment success page)
     */
    public function getByReference($reference)
    {
        $transaction = PaymentTransaction::where('transaction_id', $reference)
            ->first();

        if (!$transaction) {
            return response()->json([
                'message' => 'Transaction not found',
            ], 404);
        }

        return response()->json([
            'data' => $transaction->load('invoice', 'gateway'),
        ]);
    }
    /**
     * Process a refund for a payment transaction
     */
    public function refund(Request $request, PaymentTransaction $transaction)
    {
        \Log::info('Refund method called', [
            'transaction_id' => $transaction->transaction_id ?? 'NULL',
            'transaction_object' => get_class($transaction),
            'has_id' => isset($transaction->id),
        ]);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:500',
        ]);

        if ($transaction->status !== 'completed') {
            return response()->json([
                'message' => 'Only completed transactions can be refunded',
                'current_status' => $transaction->status,
            ], 422);
        }

        // Check if refund amount is valid
        $refundedAmount = $transaction->refunded_amount ?? 0;
        $availableForRefund = $transaction->amount - $refundedAmount;

        if ($validated['amount'] > $availableForRefund) {
            return response()->json([
                'message' => 'Refund amount exceeds available balance',
                'available_for_refund' => $availableForRefund,
            ], 422);
        }

        try {
            DB::beginTransaction();

            $gateway = $transaction->gateway;
            $service = PaymentGatewayFactory::create($gateway);

            // Process refund with payment gateway
            $refundResult = $service->refund($transaction->transaction_id, $validated['amount']);

            if (!$refundResult['success']) {
                throw new \Exception($refundResult['message'] ?? 'Refund failed');
            }

            // Update transaction with refund information
            $transaction->increment('refunded_amount', $validated['amount']);
            $transaction->update([
                'refund_reason' => $validated['reason'],
                'refunded_at' => now(),
            ]);

            // If fully refunded, update status
            if ($transaction->refunded_amount >= $transaction->amount) {
                $transaction->update(['status' => 'refunded']);
            }

            // Update invoice balance
            $invoice = $transaction->invoice;
            if ($invoice) {
                $invoice->increment('balance', $validated['amount']);
                $invoice->decrement('paid_amount', $validated['amount']);

                // Update invoice status based on payment state
                if ($invoice->paid_amount <= 0) {
                    $invoice->update(['status' => 'sent']); // Changed from 'unpaid' to 'sent'
                } elseif ($invoice->balance > 0 && $invoice->paid_amount > 0) {
                    // Partially paid - keep as sent or current status if not draft
                    if ($invoice->status !== 'paid') {
                        $invoice->update(['status' => 'sent']);
                    }
                }
            }

            DB::commit();

            \Log::info('Refund processed successfully', [
                'transaction_id' => $transaction->transaction_id,
                'refund_amount' => $validated['amount'],
                'reason' => $validated['reason'],
            ]);

            return response()->json([
                'message' => 'Refund processed successfully',
                'data' => [
                    'transaction' => $transaction->fresh(),
                    'refund_id' => $refundResult['refund_id'] ?? null,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Refund failed', [
                'transaction_id' => $transaction->transaction_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to process refund: ' . $e->getMessage(),
            ], 500);
        }
    }
}
