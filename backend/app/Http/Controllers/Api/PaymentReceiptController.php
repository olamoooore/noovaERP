<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentReceiptDelivery;
use App\Services\PaymentReceiptDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\TemplateBuilder\Services\DocumentRenderAuthorizer;

/**
 * Issue #500 — send a payment's official receipt to the customer and list its
 * delivery history (payment detail).
 *
 * Both actions authorize through the #611 DocumentRenderAuthorizer exactly
 * like the receipt render: the receipt's own read permission
 * (accounting.view) and the payment's legal entity; a missing or
 * other-entity payment answers the same 403.
 */
class PaymentReceiptController extends Controller
{
    public function __construct(
        private readonly DocumentRenderAuthorizer $authorizer,
        private readonly PaymentReceiptDeliveryService $deliveries,
    ) {}

    public function send(Request $request, string $payment): JsonResponse
    {
        if ($denied = $this->authorizer->deny('receipt', $payment, $request)) {
            return $denied;
        }

        $validated = $request->validate([
            'channel' => ['required', Rule::in(PaymentReceiptDelivery::CHANNELS)],
            'recipient' => ['nullable', 'string', 'max:255'],
        ]);

        if (($validated['channel'] === PaymentReceiptDelivery::CHANNEL_EMAIL)
            && ! empty($validated['recipient'])
            && ! filter_var($validated['recipient'], FILTER_VALIDATE_EMAIL)) {
            return response()->json(['message' => 'The recipient must be a valid email address.'], 422);
        }

        $model = Payment::findOrFail($payment);

        try {
            $delivery = $this->deliveries->deliver($model, $validated['channel'], $validated['recipient'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            // The failed attempt is already recorded on the delivery history.
            return response()->json([
                'message' => 'The receipt could not be sent: '.$e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Receipt sent.',
            'data' => $this->present($delivery),
        ], 201);
    }

    public function index(Request $request, string $payment): JsonResponse
    {
        if ($denied = $this->authorizer->deny('receipt', $payment, $request)) {
            return $denied;
        }

        $rows = PaymentReceiptDelivery::query()
            ->where('payment_id', $payment)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PaymentReceiptDelivery $d) => $this->present($d));

        return response()->json(['data' => $rows]);
    }

    private function present(PaymentReceiptDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'channel' => $delivery->channel,
            'recipient' => $delivery->recipient,
            'status' => $delivery->status,
            'error' => $delivery->error,
            'sent_by' => $delivery->sent_by,
            'sent_at' => $delivery->sent_at?->toIso8601String(),
        ];
    }
}
