<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\BillPayment;
use App\Services\PaymentCorrectionService;
use App\Services\CorrectionRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PaymentCorrectionsController extends Controller
{
    public function __construct(
        private PaymentCorrectionService $correctionService,
    ) {}

    public function refundPayment(Request $request, int $id): JsonResponse
    {
        $payment = Payment::findOrFail($id);

        $validated = $request->validate([
            'reason_code' => 'required|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        try {
            $correctionRequest = CorrectionRequest::fromRequest($validated);
            $result = $this->correctionService->refundPayment($payment, $correctionRequest);

            return response()->json([
                'message' => 'Payment refunded successfully',
                'refund_payment' => $result->payment,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reversePayment(Request $request, int $id): JsonResponse
    {
        $payment = Payment::findOrFail($id);

        $validated = $request->validate([
            'reason_code' => 'required|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        try {
            $correctionRequest = CorrectionRequest::fromRequest($validated);
            $result = $this->correctionService->reversePayment($payment, $correctionRequest);

            return response()->json([
                'message' => 'Payment reversed successfully',
                'reversal_payment' => $result->payment,
                'reversal_journal_entry' => $result->reversalJournalEntry,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function unapplyPayment(Request $request, int $id): JsonResponse
    {
        $payment = Payment::findOrFail($id);

        $validated = $request->validate([
            'reason_code' => 'required|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        try {
            $correctionRequest = CorrectionRequest::fromRequest($validated);
            $result = $this->correctionService->unapplyPayment($payment, $correctionRequest);

            return response()->json([
                'message' => 'Payment unapplied successfully',
                'payment' => $result->payment,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function refundBillPayment(Request $request, int $id): JsonResponse
    {
        $payment = BillPayment::findOrFail($id);

        $validated = $request->validate([
            'reason_code' => 'required|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        try {
            $correctionRequest = CorrectionRequest::fromRequest($validated);
            $result = $this->correctionService->refundPayment($payment, $correctionRequest);

            return response()->json([
                'message' => 'Bill payment refunded successfully',
                'refund_payment' => $result->payment,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reverseBillPayment(Request $request, int $id): JsonResponse
    {
        $payment = BillPayment::findOrFail($id);

        $validated = $request->validate([
            'reason_code' => 'required|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        try {
            $correctionRequest = CorrectionRequest::fromRequest($validated);
            $result = $this->correctionService->reversePayment($payment, $correctionRequest);

            return response()->json([
                'message' => 'Bill payment reversed successfully',
                'reversal_payment' => $result->payment,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function unapplyBillPayment(Request $request, int $id): JsonResponse
    {
        $payment = BillPayment::findOrFail($id);

        $validated = $request->validate([
            'reason_code' => 'required|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        try {
            $correctionRequest = CorrectionRequest::fromRequest($validated);
            $result = $this->correctionService->unapplyPayment($payment, $correctionRequest);

            return response()->json([
                'message' => 'Bill payment unapplied successfully',
                'payment' => $result->payment,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}