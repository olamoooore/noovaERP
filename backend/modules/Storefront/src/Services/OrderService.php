<?php

namespace Modules\Storefront\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Storefront\Enums\ActorType;
use Modules\Storefront\Enums\OrderStatus;
use Modules\Storefront\Enums\PaymentStatus;
use Modules\Storefront\Exceptions\InvalidOrderTransitionException;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontOrderPaymentAttempt;

/**
 * The ONE writer for order lifecycle state (FR-ORD-01). Every writer —
 * checkout (P2), gateway webhook (canonical completion), admin, customer —
 * calls these methods; none may set ->status / ->payment_status directly.
 *
 * Guarantees:
 *  - transition validated under FOR UPDATE row lock, re-read fresh (stale
 *    in-memory models cannot bypass the machine);
 *  - same-state transitions are IDEMPOTENT no-ops (webhook retries don't
 *    duplicate history or 500 — C6 rule 5 / A-06);
 *  - every accepted transition appends status history with actor
 *    (type,id,note) — the A-07 audit trail;
 *  - timestamp side effects (paid_at / cancelled_at) live here only.
 */
final class OrderService
{
    public function __construct(
        private readonly OrderStateMachine $machine,
    ) {}

    public function generateOrderNumber(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = 'SF-'.now()->format('ymd').'-'.strtoupper(Str::random(6));

            if (! StorefrontOrder::query()->where('order_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique storefront order number.');
    }

    /**
     * Resolve the legal entity to stamp on a new order (FR-ORD-03 entity
     * stamping). Priority: the request's resolved entity context (staff
     * routes via ResolveEntityContext) → the customer's own entity (mirrors
     * SalesOrderService) → null (single-entity tenants / later backfill).
     */
    public function resolveLegalEntityId(?\App\Models\Customer $customer): ?int
    {
        $fromRequest = request()?->attributes->get('legal_entity_id');
        if ($fromRequest !== null && $fromRequest !== '') {
            return (int) $fromRequest;
        }

        return $customer?->legal_entity_id !== null ? (int) $customer->legal_entity_id : null;
    }

    public function transitionOrder(
        StorefrontOrder $order,
        OrderStatus $to,
        ActorType $actorType,
        ?int $actorId = null,
        ?string $note = null,
    ): StorefrontOrder {
        return DB::transaction(function () use ($order, $to, $actorType, $actorId, $note) {
            /** @var StorefrontOrder $locked */
            $locked = StorefrontOrder::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $from = $locked->status;

            // Idempotent replay: same state is a silent success (no second
            // history row) so webhook/retry deliveries converge.
            if ($from === $to->value) {
                return $locked;
            }

            if (! $this->machine->canTransitionOrder($from, $to->value)) {
                throw new InvalidOrderTransitionException(
                    'order',
                    $from,
                    $to->value,
                    $this->machine->allowedOrderTransitions($from),
                    $actorType->value,
                );
            }

            $locked->status = $to->value;

            if ($to === OrderStatus::PAID && $locked->paid_at === null) {
                $locked->paid_at = now();
            }
            if ($to === OrderStatus::CANCELLED && $locked->cancelled_at === null) {
                $locked->cancelled_at = now();
            }

            $locked->save();

            $locked->statusHistory()->create([
                'from_status' => $from,
                'to_status' => $to->value,
                'actor_type' => $actorType->value,
                'actor_id' => $actorId,
                'note' => $note,
            ]);

            return $locked;
        });
    }

    public function transitionPayment(
        StorefrontOrder $order,
        PaymentStatus $to,
        ActorType $actorType,
        ?int $actorId = null,
        ?string $note = null,
    ): StorefrontOrder {
        return DB::transaction(function () use ($order, $to, $actorType, $actorId, $note) {
            /** @var StorefrontOrder $locked */
            $locked = StorefrontOrder::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $from = $locked->payment_status;

            if ($from === $to->value) {
                return $locked;
            }

            if (! $this->machine->canTransitionPayment($from, $to->value)) {
                throw new InvalidOrderTransitionException(
                    'payment',
                    $from,
                    $to->value,
                    $this->machine->allowedPaymentTransitions($from),
                    $actorType->value,
                );
            }

            $locked->payment_status = $to->value;

            if ($to === PaymentStatus::PAID && $locked->paid_at === null) {
                $locked->paid_at = now();
            }

            $locked->save();

            $locked->statusHistory()->create([
                'from_status' => $from,
                'to_status' => $to->value,
                'actor_type' => $actorType->value,
                'actor_id' => $actorId,
                'note' => 'payment: '.($note ?? ''),
            ]);

            return $locked;
        });
    }

    public function recordPaymentAttempt(
        StorefrontOrder $order,
        string $gateway,
        ?string $transactionRef,
        string $status,
        ?string $error = null,
    ): StorefrontOrderPaymentAttempt {
        $attemptNo = (int) StorefrontOrderPaymentAttempt::query()
            ->where('order_id', $order->getKey())
            ->max('attempt_no') + 1;

        return $this->insertAttemptWithRetry($order, $gateway, $transactionRef, $status, $error, $attemptNo);
    }

    private function insertAttemptWithRetry(
        StorefrontOrder $order,
        string $gateway,
        ?string $transactionRef,
        string $status,
        ?string $error,
        int $attemptNo,
    ): StorefrontOrderPaymentAttempt {
        try {
            return StorefrontOrderPaymentAttempt::query()->create([
                'order_id' => $order->getKey(),
                'attempt_no' => $attemptNo,
                'gateway' => $gateway,
                'transaction_ref' => $transactionRef,
                'status' => $status,
                'error' => $error,
            ]);
        } catch (QueryException $e) {
            // Concurrent attempt from another writer incremented the number;
            // one bounded retry keeps attempts gapless under races.
            if ($attemptNo < 10 && $this->isUniqueViolation($e)) {
                return $this->insertAttemptWithRetry($order, $gateway, $transactionRef, $status, $error, $attemptNo + 1);
            }

            throw $e;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return str_contains($e->getMessage(), '23505') || str_contains($e->getMessage(), 'Unique violation');
    }
}
