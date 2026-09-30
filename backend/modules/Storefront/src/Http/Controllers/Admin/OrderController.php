<?php

namespace Modules\Storefront\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Storefront\Enums\ActorType;
use Modules\Storefront\Enums\OrderStatus;
use Modules\Storefront\Exceptions\CheckoutException;
use Modules\Storefront\Exceptions\InvalidOrderTransitionException;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Services\OrderStateMachine;

/**
 * Tenant staff order console API (FR-ADM-01/04, Appendix E §E4.4).
 *
 * Status changes go through the SAME OrderStateMachine as checkout,
 * webhooks and the customer dashboard (FR-ORD-01) — staff get no side
 * door; the machine answers 409 with its allowed set when a move is
 * illegal (e.g. cancelling a paid order must go via refunds).
 */
final class OrderController extends Controller
{
    public function __construct(
        private readonly OrderStateMachine $machine,
        private readonly \Modules\Storefront\Services\OrderService $orders,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = StorefrontOrder::query()->latest('id');

        if (($status = $request->string('status')->toString()) !== '') {
            if (in_array($status, OrderStatus::values(), true)) {
                $query->where('status', $status);
            }
        }
        if (($payment = $request->string('payment_status')->toString()) !== ''
            && in_array($payment, \Modules\Storefront\Enums\PaymentStatus::values(), true)) {
            $query->where('payment_status', $payment);
        }
        if (($search = $request->string('search')->toString()) !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function ($q) use ($needle) {
                $q->whereRaw('lower(order_number) LIKE ?', [$needle])
                    ->orWhereRaw('lower(email) LIKE ?', [$needle]);
            });
        }

        $page = $query->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return response()->json([
            'data' => collect($page->items())->map(fn (StorefrontOrder $o) => [
                'id' => $o->id,
                'order_number' => $o->order_number,
                'email' => $o->email,
                'account_id' => $o->account_id,
                'customer_id' => $o->customer_id,
                'status' => $o->status,
                'payment_status' => $o->payment_status,
                'subtotal' => (string) $o->subtotal,
                'total' => (string) $o->total,
                'currency' => $o->currency,
                'channel' => $o->channel,
                'placed_at' => optional($o->placed_at)->toIso8601String(),
                'paid_at' => optional($o->paid_at)->toIso8601String(),
                'created_at' => optional($o->created_at)->toIso8601String(),
            ]),
            'meta' => [
                'page' => $page->currentPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'allowed_transitions' => $this->machine::ORDER_TRANSITIONS,
            ],
        ]);
    }

    public function show(int $order): JsonResponse
    {
        $model = StorefrontOrder::query()
            ->with(['items', 'statusHistory', 'paymentAttempts', 'account', 'customer'])
            ->find($order);

        if ($model === null) {
            return response()->json(['message' => 'Order not found.', 'code' => 'not_found'], 404);
        }

        $base = $model->only([
            'id', 'order_number', 'email', 'phone', 'status', 'payment_status',
            'currency', 'channel', 'legal_entity_id',
            'account_id', 'customer_id', 'invoice_id', 'sales_order_id',
        ]);

        return response()->json([
            'data' => array_merge($base, [
                'totals' => [
                    'subtotal' => (string) $model->subtotal,
                    'discount_amount' => (string) $model->discount_amount,
                    'shipping' => (string) $model->shipping_amount,
                    'tax' => (string) $model->tax_amount,
                    'total' => (string) $model->total,
                ],
                'shipping_address' => $model->shipping_address,
                'billing_address' => $model->billing_address,
                'shipping_method' => $model->shipping_method,
                'tax_breakdown' => $model->tax_breakdown,
                'tracking_numbers' => $model->tracking_numbers ?? [],
                'placed_at' => optional($model->placed_at)->toIso8601String(),
                'paid_at' => optional($model->paid_at)->toIso8601String(),
                'cancelled_at' => optional($model->cancelled_at)->toIso8601String(),
                'items' => $model->items->map(fn ($i) => $i->only([
                    'id', 'product_id', 'variant_id', 'name', 'sku', 'unit_price',
                    'quantity', 'tax_rate', 'tax_amount', 'discount_amount', 'line_total',
                    'fulfilled_quantity', 'returned_quantity',
                ])),
                'history' => $model->statusHistory->map(fn ($h) => [
                    'from' => $h->from_status, 'to' => $h->to_status,
                    'actor_type' => $h->actor_type, 'actor_id' => $h->actor_id,
                    'note' => $h->note, 'at' => optional($h->created_at)->toIso8601String(),
                ]),
                'payment_attempts' => $model->paymentAttempts->map(fn ($a) => $a->only([
                    'id', 'attempt_no', 'gateway', 'transaction_ref', 'status', 'error',
                ])),
                'refunds' => $this->refundsFor($model),
                'payment_proofs' => $model->paymentProofs()->get()->map(fn ($p) => [
                    'id' => $p->id,
                    'method' => $p->method,
                    'reference' => $p->reference,
                    'file_name' => $p->file_name,
                    'mime_type' => $p->mime_type,
                    'size_bytes' => (int) $p->size_bytes,
                    'status' => $p->status,
                    'review_note' => $p->review_note,
                    'file_url' => "/api/v1/admin/storefront/orders/{$model->id}/proofs/{$p->id}/file",
                    'created_at' => optional($p->created_at)->toIso8601String(),
                ]),
                'allowed_transitions' => $this->machine->allowedOrderTransitions($model->status),
            ]),
        ]);
    }

    public function updateStatus(Request $request, int $order): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', OrderStatus::values())],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $model = StorefrontOrder::query()->find($order);
        if ($model === null) {
            return response()->json(['message' => 'Order not found.', 'code' => 'not_found'], 404);
        }

        try {
            $updated = $this->orders->transitionOrder(
                $model,
                OrderStatus::from($validated['status']),
                ActorType::STAFF,
                $this->staffActorId(),
                $validated['note'] ?? null,
            );
        } catch (InvalidOrderTransitionException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'invalid_transition',
                'allowed' => $e->allowedFrom,
            ], 409);
        }

        return response()->json(['data' => ['id' => $updated->id, 'status' => $updated->status]]);
    }

    /**
     * FR-ORD-05 partial shipment entry. Logistics only — stock was issued
     * once, canonically, at payment (SF015). Tracking presence decides the
     * machine move: packed (processing) vs dispatched (shipped).
     */
    public function fulfil(Request $request, int $order, \Modules\Storefront\Services\StorefrontFulfilmentService $fulfilment): JsonResponse
    {
        $validated = $request->validate([
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'tracking_number' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $model = StorefrontOrder::query()->find($order);
        if ($model === null) {
            return response()->json(['message' => 'Order not found.', 'code' => 'not_found'], 404);
        }

        try {
            $out = $fulfilment->fulfil(
                $model,
                array_map(fn (array $l) => ['line_id' => (int) $l['line_id'], 'quantity' => (float) $l['quantity']], $validated['lines']),
                $validated['tracking_number'] ?? null,
                $validated['note'] ?? null,
                $this->staffActorId(),
            );
        } catch (CheckoutException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        } catch (InvalidOrderTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_transition', 'allowed' => $e->allowedFrom], 409);
        }

        return response()->json(['data' => $out]);
    }

    /** Staff audit reference: central user id (users are central; see RequirePermission). */
    private function staffActorId(): ?int
    {
        return Auth::id() !== null ? (int) Auth::id() : null;
    }

    /**
     * Platform-initiated refund entry (FR-ORD-04, Appendix E §E4.4
     * `/…/refund`). Deliberately records and reverses ONLY — the money is
     * settled off-platform (gateway dashboard / bank), mirroring A-18's
     * platform direction. The gateway-issuing direction lives on the platform
     * payments endpoint (which delegates here for storefront transactions via
     * the StorefrontOrderRefundHandler seam). All durable bookkeeping runs
     * through the shared PaymentRefundService; state through the machine.
     */
    public function refund(Request $request, int $order, \Modules\Storefront\Services\StorefrontRefundService $refunds): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $model = StorefrontOrder::query()->find($order);
        if ($model === null) {
            return response()->json(['message' => 'Order not found.', 'code' => 'not_found'], 404);
        }

        try {
            // Machine-level refund eligibility answered FIRST (the order, not
            // the payment, decides whether a refund applies at all).
            if (! in_array((string) $model->payment_status, [\Modules\Storefront\Enums\PaymentStatus::PAID->value, \Modules\Storefront\Enums\PaymentStatus::PARTIALLY_REFUNDED->value], true)) {
                throw new CheckoutException('not_refundable', 'Only settled payments can be refunded on this order.', 422);
            }

            $transaction = $refunds->settledTransactionFor($model);

            $amount = array_key_exists('amount', $validated) && $validated['amount'] !== null
                ? (float) $validated['amount']
                : round((float) $transaction->amount - (float) ($transaction->refunded_amount ?? 0), 2);

            $out = $refunds->refund(
                $transaction,
                $amount,
                (string) $validated['reason'],
                viaGateway: false,
                actorUserId: $this->staffActorId(),
                idempotencyKey: $request->header('Idempotency-Key') ?: null,
            );

            $fresh = StorefrontOrder::query()->findOrFail($model->id);
        } catch (CheckoutException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        } catch (InvalidOrderTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_transition', 'allowed' => $e->allowedFrom], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'refund_exceeds_balance'], 422);
        }

        return response()->json([
            'data' => [
                'order_id' => $fresh->id,
                'status' => $fresh->status,
                'payment_status' => $fresh->payment_status,
                'refund' => [
                    'id' => $out['intent']->id,
                    'refund_number' => $out['intent']->refund_number,
                    'amount' => (string) $out['intent']->refund_amount,
                    'status' => $out['intent']->status,
                    'journal_entry_id' => $out['journal_entry']->id,
                ],
                'transaction_refunded_amount' => (string) ($out['result']['transaction']->refunded_amount ?? '0.00'),
            ],
        ]);
    }

    /** Refund history for the order detail console (FR-ADM-04 audit list). */
    private function refundsFor(StorefrontOrder $order): array
    {
        $transactionIds = \Modules\PaymentGateway\Models\PaymentTransaction::query()
            ->where('metadata->type', 'storefront_order')
            ->where('metadata->order_id', (int) $order->id)
            ->pluck('id');

        if ($transactionIds->isEmpty()) {
            return [];
        }

        return \App\Models\RefundTransaction::query()
            ->where('source_type', \Modules\PaymentGateway\Models\PaymentTransaction::class)
            ->whereIn('source_id', $transactionIds)
            ->orderBy('id')
            ->get(['id', 'refund_number', 'refund_amount', 'status', 'reason', 'requested_at', 'completed_at'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'refund_number' => $r->refund_number,
                'amount' => (string) $r->refund_amount,
                'status' => $r->status,
                'reason' => $r->reason,
                'requested_at' => optional($r->requested_at)->toIso8601String(),
                'completed_at' => optional($r->completed_at)->toIso8601String(),
            ])
            ->all();
    }
}
