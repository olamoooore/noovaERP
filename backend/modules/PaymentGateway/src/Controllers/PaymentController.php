<?php

namespace Modules\PaymentGateway\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentGatewayFactory;
use App\Services\AccountingService;
use Modules\POS\Models\PosOrder;

class PaymentController extends Controller
{
    /**
     * Create a new payment
     */
    public function create(Request $request)
    {
        $validated = $request->validate([
            'gateway_id' => 'required|exists:payment_gateways,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'pos_order_id' => 'nullable|exists:pos_orders,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'sometimes|string|size:3',
            'email' => 'sometimes|email',
            'redirect_url' => 'sometimes|string',
        ]);

        // Must have either invoice_id or pos_order_id
        if (empty($validated['invoice_id']) && empty($validated['pos_order_id'])) {
            return response()->json([
                'message' => 'Either invoice_id or pos_order_id is required',
            ], 422);
        }

        $gateway = PaymentGateway::findOrFail($validated['gateway_id']);
        
        // Get payment source (invoice or POS order)
        $invoice = null;
        $posOrder = null;
        $customerEmail = null;
        $description = '';
        $reference = '';
        
        if (!empty($validated['invoice_id'])) {
            $invoice = Invoice::findOrFail($validated['invoice_id']);
            $customerEmail = $invoice->customer->email ?? null;
            $description = "Payment for Invoice {$invoice->invoice_number}";
            $reference = $invoice->invoice_number;
        } else {
            $posOrder = PosOrder::findOrFail($validated['pos_order_id']);
            $customerEmail = $posOrder->customer_email ?? null;
            $description = "Payment for POS Order {$posOrder->order_number}";
            $reference = $posOrder->order_number;
        }

        if (!$gateway->is_active) {
            return response()->json([
                'message' => 'Payment gateway is not active',
            ], 422);
        }

        try {
            $service = PaymentGatewayFactory::create($gateway);

            // Determine currency: priority is validated > invoice > POS order > gateway defaults
            $currency = $validated['currency'] ?? $invoice?->currency ?? $posOrder?->currency ?? null;
            if (!$currency) {
                // Default currencies by gateway
                $currency = match ($gateway->gateway_name) {
                    'hitpay' => 'SGD',
                    'paypal' => 'MYR',
                    'stripe' => 'MYR',
                    default => 'MYR',
                };
            }

            $frontendUrl = config('app.frontend_url', 'http://localhost:5173');
            $redirectUrl = $validated['redirect_url'] ?? ($frontendUrl . '/payment/success');
            $paymentData = [
                'amount' => $validated['amount'],
                'currency' => $currency,
                'email' => $validated['email'] ?? $customerEmail,
                'description' => $description,
                'reference' => $reference,
                'redirect_url' => $redirectUrl,
                'metadata' => [
                    'invoice_id' => $invoice?->id,
                    'pos_order_id' => $posOrder?->id,
                    'customer_id' => $invoice?->customer_id ?? $posOrder?->customer_id,
                ],
            ];

            \Log::info('Creating payment with currency', [
                'invoice_id' => $invoice?->id,
                'pos_order_id' => $posOrder?->id,
                'payment_currency' => $currency,
                'gateway' => $gateway->gateway_name,
            ]);

            $result = $service->createPayment($paymentData);

            if ($result['success']) {
                // Clean up old pending transactions for this invoice/order (older than 30 minutes)
                $query = PaymentTransaction::where('status', 'pending')
                    ->where('created_at', '<', now()->subMinutes(30));
                
                if ($invoice) {
                    $query->where('invoice_id', $invoice->id);
                } elseif ($posOrder) {
                    $query->whereJsonContains('metadata->pos_order_id', $posOrder->id);
                }
                $query->delete();

                // Merge metadata from gateway response (e.g., approve_link for PayPal)
                $transactionMetadata = array_merge(
                    $paymentData['metadata'],
                    $result['metadata'] ?? []
                );

                // Use firstOrCreate to handle idempotency atomically
                // Note: payment_transactions table is in public schema.
                // For POS orders, customer_id may be in tenant schema only,
                // so we skip it to avoid FK violations. It's stored in metadata.
                $customerId = $invoice?->customer_id ?? null;

                $transaction = PaymentTransaction::firstOrCreate(
                    ['transaction_id' => $result['transaction_id']], // Find by this
                    [ // Create with these values if not found
                        'payment_gateway_id' => $gateway->id,
                        'invoice_id' => $invoice?->id,
                        'customer_id' => $customerId,
                        'amount' => $validated['amount'],
                        'currency' => $currency,
                        'status' => 'pending',
                        'metadata' => $transactionMetadata,
                    ]
                );

                if ($transaction->wasRecentlyCreated) {
                    \Log::info('Created new payment transaction', [
                        'transaction_id' => $transaction->transaction_id,
                        'invoice_id' => $invoice?->id,
                        'pos_order_id' => $posOrder?->id,
                        'metadata' => $transactionMetadata,
                    ]);
                } else {
                    \Log::info('Returning existing payment transaction (idempotency)', [
                        'transaction_id' => $transaction->transaction_id,
                        'invoice_id' => $invoice?->id,
                        'pos_order_id' => $posOrder?->id,
                    ]);
                }

                $response = [
                    'message' => 'Payment initiated successfully',
                    'transaction' => $transaction,
                    'payment_url' => $result['payment_url'] ?? null,
                    'client_secret' => $result['client_secret'] ?? null,
                    'publishable_key' => $gateway->publishable_key ?? null,
                    'is_test_mode' => $gateway->is_test_mode ?? false,
                    'gateway_name' => $gateway->gateway_name,
                ];
                
                if ($invoice) {
                    $response['invoice'] = $invoice->load('customer');
                } elseif ($posOrder) {
                    $response['pos_order'] = $posOrder;
                }
                
                return response()->json($response, 201);
            }

            return response()->json([
                'message' => 'Payment creation failed',
                'error' => $result['error'] ?? 'Unknown error',
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Payment creation failed', [
                'invoice_id' => $validated['invoice_id'] ?? null,
                'pos_order_id' => $validated['pos_order_id'] ?? null,
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
                    'invoice' => $transaction->invoice?->fresh(),
                    'pos_order' => !empty($transaction->metadata['pos_order_id']) ? \Modules\POS\Models\PosOrder::find($transaction->metadata['pos_order_id']) : null,
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
            
            // Handle POS order payments
            if (!$invoice && !empty($transaction->metadata['pos_order_id'])) {
                $posOrder = PosOrder::find($transaction->metadata['pos_order_id']);
                if ($posOrder) {
                    $this->processCompletedPosOrderPayment($transaction, $posOrder);
                    return;
                }
            }
            
            if (!$invoice) {
                \Log::error('No invoice or POS order found for transaction', [
                    'transaction_id' => $transaction->transaction_id,
                    'metadata' => $transaction->metadata,
                ]);
                return;
            }
            
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
     * Process completed payment for POS order (internal helper)
     */
    protected function processCompletedPosOrderPayment(PaymentTransaction $transaction, PosOrder $posOrder)
    {
        // First, update POS order status (critical - must succeed)
        DB::transaction(function () use ($transaction, $posOrder) {
            \Log::info('Processing completed POS order payment', [
                'transaction_id' => $transaction->transaction_id,
                'pos_order_id' => $posOrder->id,
                'pos_order_status' => $posOrder->status,
            ]);

            // Update POS order status to 'paid' (consistent with PosOrderController)
            $posOrder->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);
            
            \Log::info('POS order status updated to paid', [
                'pos_order_id' => $posOrder->id,
                'new_status' => $posOrder->fresh()->status,
            ]);

            // Create POS payment record
            $receivedBy = auth()->id() ?? $posOrder->created_by ?? 1; // Fallback to user ID 1 (admin)
            \Modules\POS\Models\PosPayment::create([
                'pos_order_id' => $posOrder->id,
                'method' => 'card', // Online card payment
                'amount' => $transaction->amount,
                'reference' => $transaction->transaction_id,
                'notes' => "Online payment via {$transaction->gateway->gateway_name}",
                'received_by' => $receivedBy,
            ]);

            // Mark transaction as processed
            $transaction->update(['processed_at' => now()]);
        });

        // Then try to create invoice (optional - failure shouldn't affect order status)
        try {
            $invoice = $this->createInvoiceFromPosOrder($posOrder, $transaction);
            
            if ($invoice) {
                // Update transaction with invoice_id
                $transaction->update(['invoice_id' => $invoice->id]);
            }

            \Log::info('POS order payment processing completed', [
                'transaction_id' => $transaction->transaction_id,
                'pos_order_id' => $posOrder->id,
                'invoice_id' => $invoice?->id,
                'amount' => $transaction->amount,
            ]);
        } catch (\Exception $e) {
            \Log::warning('Invoice creation failed but order is marked as paid', [
                'transaction_id' => $transaction->transaction_id,
                'pos_order_id' => $posOrder->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create Invoice from POS Order
     */
    protected function createInvoiceFromPosOrder(PosOrder $posOrder, PaymentTransaction $transaction): ?Invoice
    {
        try {
            // Get or create walk-in customer for orders without customer_id
            $customerId = $posOrder->customer_id;
            if (!$customerId) {
                $walkInCustomer = \App\Models\Customer::firstOrCreate(
                    ['email' => 'walkin@system.local'],
                    [
                        'name' => 'Walk-in Customer',
                        'phone' => null,
                        'address' => null,
                        'notes' => 'System customer for walk-in POS transactions',
                    ]
                );
                $customerId = $walkInCustomer->id;
                
                \Log::info('Using walk-in customer for invoice', [
                    'pos_order_id' => $posOrder->id,
                    'walk_in_customer_id' => $customerId,
                ]);
            }

            // Create invoice
            $invoice = Invoice::create([
                'customer_id' => $customerId,
                'invoice_date' => now(),
                'due_date' => now(),
                'status' => 'paid',
                'subtotal' => $posOrder->subtotal,
                'tax_amount' => $posOrder->tax_amount,
                'discount_amount' => $posOrder->discount_amount ?? 0,
                'total' => $posOrder->total,
                'paid_amount' => $posOrder->total,
                'balance' => 0,
                'notes' => "Generated from POS Order: {$posOrder->order_number}",
                'created_by' => $posOrder->created_by,
            ]);

            // Create invoice lines from POS order items
            foreach ($posOrder->items as $item) {
                $description = $item->product_name ?? $item->product?->name ?? 'Product';
                $invoice->lines()->create([
                    'product_id' => $item->product_id,
                    'description' => $description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'tax_rate' => 0, // POS items already include tax in tax_amount
                    'tax_amount' => $item->tax_amount / $item->quantity, // per unit
                    'discount_amount' => 0,
                    'line_total' => $item->total_price,
                ]);
            }

            \Log::info('Invoice created from POS order', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'pos_order_id' => $posOrder->id,
            ]);

            return $invoice;
        } catch (\Exception $e) {
            \Log::error('Failed to create invoice from POS order', [
                'pos_order_id' => $posOrder->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
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
