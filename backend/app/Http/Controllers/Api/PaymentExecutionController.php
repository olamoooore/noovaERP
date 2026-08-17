<?php

namespace App\Http\Controllers\Api;

use App\Accounting\Enums\PaymentExecutionState;
use App\Http\Controllers\Controller;
use App\Models\PaymentExecution;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentExecutionController extends Controller
{
    /**
     * GET /accounting/payment-executions
     * List payment executions with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PaymentExecution::query();

        if ($request->has('payment_id')) {
            $query->forPayment($request->input('payment_id'));
        }

        if ($request->has('state')) {
            $query->where('execution_state', $request->input('state'));
        }

        if ($request->has('unknown_outcome')) {
            $query->unknownOutcome();
        }

        $executions = $query->orderByDesc('created_at')->paginate(25);

        return response()->json([
            'success' => true,
            'data' => $executions,
        ]);
    }

    /**
     * GET /accounting/payment-executions/{id}
     * Get a specific payment execution.
     */
    public function show(int $id): JsonResponse
    {
        $execution = PaymentExecution::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $execution,
        ]);
    }

    /**
     * POST /accounting/payment-executions
     * Create a new payment execution.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payment_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.01',
            'currency_code' => 'nullable|string|max:10',
            'payment_method' => 'nullable|string|max:50',
            'bank_account_id' => 'nullable|integer',
            'provider_name' => 'nullable|string|max:100',
            'approval_required' => 'nullable|boolean',
        ]);

        $execution = PaymentExecution::create([
            'tenant_id' => auth()->user()?->tenant_id,
            'entity_id' => $request->input('entity_id'),
            'payment_id' => $validated['payment_id'],
            'amount' => $validated['amount'],
            'currency_code' => $validated['currency_code'] ?? 'USD',
            'payment_method' => $validated['payment_method'],
            'bank_account_id' => $validated['bank_account_id'],
            'provider_name' => $validated['provider_name'],
            'approval_required' => $validated['approval_required'] ?? false,
            'initiated_by' => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $execution,
        ], 201);
    }

    /**
     * POST /accounting/payment-executions/{id}/submit
     * Submit a payment execution to the provider.
     */
    public function submit(int $id, Request $request): JsonResponse
    {
        $execution = PaymentExecution::findOrFail($id);

        if (!$execution->canSubmit()) {
            return response()->json([
                'success' => false,
                'error' => 'Payment execution cannot be submitted in current state: ' . $execution->execution_state,
            ], 422);
        }

        $providerReference = $request->input('provider_reference', 'REF-' . uniqid());
        $idempotencyKey = $request->input('idempotency_key');

        $execution->markSubmitted($providerReference, $idempotencyKey);

        event(new \App\Accounting\Events\PaymentInitiated(
            paymentExecutionId: $execution->id,
            paymentId: $execution->payment_id,
            amount: $execution->amount,
            currencyCode: $execution->currency_code,
            method: $execution->payment_method ?? 'unknown',
            correlationId: $request->input('correlation_id'),
            tenantId: $execution->tenant_id,
        ));

        return response()->json([
            'success' => true,
            'data' => $execution,
        ]);
    }

    /**
     * POST /accounting/payment-executions/{id}/complete
     * Mark a payment execution as completed.
     */
    public function complete(int $id, Request $request): JsonResponse
    {
        $execution = PaymentExecution::findOrFail($id);

        $execution->markCompleted($request->input('settlement_reference'));

        event(new \App\Accounting\Events\PaymentCompleted(
            paymentExecutionId: $execution->id,
            paymentId: $execution->payment_id,
            amount: $execution->amount,
            providerReference: $execution->provider_reference,
            settlementReference: $request->input('settlement_reference'),
            tenantId: $execution->tenant_id,
        ));

        return response()->json([
            'success' => true,
            'data' => $execution,
        ]);
    }

    /**
     * POST /accounting/payment-executions/{id}/unknown-outcome
     * Mark submission outcome as unknown (SUBMISSION_UNKNOWN).
     */
    public function markUnknownOutcome(int $id): JsonResponse
    {
        $execution = PaymentExecution::findOrFail($id);

        $execution->markSubmissionUnknown();

        event(new \App\Accounting\Events\PaymentSubmissionUnknown(
            paymentExecutionId: $execution->id,
            reason: 'Transport response lost or timeout',
            tenantId: $execution->tenant_id,
        ));

        return response()->json([
            'success' => true,
            'data' => $execution,
            'message' => 'Payment is now SUBMITTED_UNKNOWN. Do not retry until provider confirms.',
        ]);
    }

    /**
     * POST /accounting/payment-executions/{id}/fail
     * Mark a payment execution as failed.
     */
    public function fail(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $execution = PaymentExecution::findOrFail($id);
        $execution->markFailed($validated['reason']);

        event(new \App\Accounting\Events\PaymentFailed(
            paymentExecutionId: $execution->id,
            reason: $validated['reason'],
            tenantId: $execution->tenant_id,
        ));

        return response()->json([
            'success' => true,
            'data' => $execution,
        ]);
    }
}
