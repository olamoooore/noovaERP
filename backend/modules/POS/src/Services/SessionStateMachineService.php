<?php

namespace Modules\POS\Services;

use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosSessionStateHistory;
use Illuminate\Support\Facades\Log;

class SessionStateMachineService
{
    // Canonical display states
    const STATES = [
        'IDLE_SCREENSAVER',
        'ACTIVE_NO_CART',
        'MEMBER_NO_CART',
        'CART_ACTIVE',
        'PAYMENT_PENDING',
        'PAYMENT_APPROVED',
        'PAYMENT_FAILED',
        'COMPLETED',
        'LOCKED_REVIEW',
    ];

    // Allowed transitions: [from_state => [event => to_state]]
    const TRANSITIONS = [
        'IDLE_SCREENSAVER' => [
            'SessionWokeUp'              => 'ACTIVE_NO_CART',
            'CartItemAdded'              => 'CART_ACTIVE',
            'CartItemQtyChanged'         => 'CART_ACTIVE',
            'MemberIdentified'           => 'MEMBER_NO_CART',
            'MemberAttachedToSession'    => 'MEMBER_NO_CART',
        ],
        'ACTIVE_NO_CART' => [
            'MemberAttachedToSession'    => 'MEMBER_NO_CART',
            'MemberIdentified'           => 'MEMBER_NO_CART',
            'CartItemAdded'              => 'CART_ACTIVE',
            'CartItemQtyChanged'         => 'CART_ACTIVE',
        ],
        'MEMBER_NO_CART' => [
            'CartItemAdded'              => 'CART_ACTIVE',
            'CartItemQtyChanged'         => 'CART_ACTIVE',
            'MemberDetachedFromSession'  => 'ACTIVE_NO_CART',
        ],
        'CART_ACTIVE' => [
            'CartItemAdded'              => 'CART_ACTIVE',
            'CartItemQtyChanged'         => 'CART_ACTIVE',
            'CartItemRemoved'            => 'CART_ACTIVE',
            'CartCleared'                => 'ACTIVE_NO_CART',
            'PaymentStarted'             => 'PAYMENT_PENDING',
            'PaymentApproved'            => 'PAYMENT_APPROVED',
            'VoidReviewStarted'          => 'LOCKED_REVIEW',
            'PriceOverrideStarted'       => 'LOCKED_REVIEW',
        ],
        'LOCKED_REVIEW' => [
            'VoidReviewResolved'         => 'CART_ACTIVE',
            'PriceOverrideCompleted'     => 'CART_ACTIVE',
        ],
        'PAYMENT_PENDING' => [
            'PaymentApproved'            => 'PAYMENT_APPROVED',
            'PaymentFailed'              => 'PAYMENT_FAILED',
        ],
        'PAYMENT_APPROVED' => [
            'SessionCompleted'           => 'COMPLETED',
            'CartItemAdded'              => 'CART_ACTIVE',
        ],
        'PAYMENT_FAILED' => [
            'PaymentRetryStarted'        => 'PAYMENT_PENDING',
            'CartCleared'                => 'ACTIVE_NO_CART',
            'CartItemAdded'              => 'CART_ACTIVE',
        ],
        'COMPLETED' => [
            'SessionReset'               => 'IDLE_SCREENSAVER',
            'SessionTimedOut'            => 'IDLE_SCREENSAVER',
            'CartItemAdded'              => 'CART_ACTIVE',
        ],
    ];

    /**
     * Transition a session to a new display state via a named event.
     * Records the transition in state history.
     * Returns the new state or null if transition was not valid.
     */
    public function transition(PosSession $session, string $eventName, array $metadata = []): ?string
    {
        $currentState = $session->current_display_state ?? 'IDLE_SCREENSAVER';

        // Special: if cart has member and CartCleared happens, go to MEMBER_NO_CART if member present
        if ($eventName === 'CartCleared' && $session->member_id) {
            $toState = 'MEMBER_NO_CART';
        } else {
            $allowedTransitions = self::TRANSITIONS[$currentState] ?? [];
            if (! array_key_exists($eventName, $allowedTransitions)) {
                Log::debug("POS display: no transition for event [{$eventName}] from state [{$currentState}]");
                return null;
            }
            $toState = $allowedTransitions[$eventName];
        }

        // Persist
        $session->update([
            'current_display_state' => $toState,
            'last_activity_at'      => now(),
        ]);

        PosSessionStateHistory::create([
            'session_id'   => $session->id,
            'from_state'   => $currentState,
            'event_name'   => $eventName,
            'to_state'     => $toState,
            'metadata_json' => $metadata ?: null,
            'created_at'   => now(),
        ]);

        return $toState;
    }

    /**
     * Force-set a state, bypassing transition rules (use for session open/wake).
     */
    public function forceState(PosSession $session, string $state, string $reason = 'forced'): void
    {
        $from = $session->current_display_state ?? 'IDLE_SCREENSAVER';

        $session->update([
            'current_display_state' => $state,
            'last_activity_at'      => now(),
        ]);

        PosSessionStateHistory::create([
            'session_id'  => $session->id,
            'from_state'  => $from,
            'event_name'  => 'ForcedTransition',
            'to_state'    => $state,
            'reason_code' => $reason,
            'created_at'  => now(),
        ]);
    }

    public function currentState(PosSession $session): string
    {
        return $session->current_display_state ?? 'IDLE_SCREENSAVER';
    }
}
