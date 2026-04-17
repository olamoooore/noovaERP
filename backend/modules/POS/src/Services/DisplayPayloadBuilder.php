<?php

namespace Modules\POS\Services;

use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosDisplayPayload;

class DisplayPayloadBuilder
{
    public function __construct(
        private CartProjectionService   $cartProjection,
        private CartFocusService        $cartFocus,
        private MemberDisplayProfileService $memberProfile,
        private PaymentStateService     $paymentState,
        private MediaResolverService    $mediaResolver,
    ) {}

    /**
     * Build the complete customer-safe display payload for the given session.
     * Returns the payload array (does NOT persist it — call DisplayAuditService for that).
     */
    public function build(
        PosSession $session,
        array $cartItems = [],
        string $focusReason = 'item_added',
        ?array $focusItem = null
    ): array {
        $terminal = $session->terminal;
        $displayState = $session->current_display_state ?? 'IDLE_SCREENSAVER';
        $isCartActive = in_array($displayState, ['CART_ACTIVE', 'PAYMENT_PENDING', 'PAYMENT_APPROVED', 'PAYMENT_FAILED', 'COMPLETED', 'LOCKED_REVIEW']);

        // ── Terminal & Session blocks ──────────────────────────────────────
        $terminalBlock = [
            'code' => $terminal->code ?? '',
            'name' => $terminal->name ?? '',
        ];

        $sessionBlock = [
            'id'               => $session->id,
            'state'            => $displayState,
            'started_at'       => $session->opened_at?->toIso8601String(),
            'last_activity_at' => $session->last_activity_at?->toIso8601String(),
        ];

        // ── Store block ───────────────────────────────────────────────────
        $storeBlock = [
            'name'             => config('app.name', 'NoovaERP'),
            'time'             => now()->toIso8601String(),
            'campaign_message' => null,
        ];

        // ── Member block (MEMBER_NO_CART only) ────────────────────────────
        $memberBlock = $session->member_id
            ? $this->memberProfile->buildForSession($session->member_id)
            : $this->memberProfile->unidentified();

        // ── Cart projection ───────────────────────────────────────────────
        $cartBlock = $isCartActive
            ? $this->cartProjection->build($session)
            : $this->cartProjection->build($session); // always include for safety; ui_flags hide it

        // ── Cart focus (CART_ACTIVE only) ─────────────────────────────────
        $cartFocusBlock = null;
        if ($displayState === 'CART_ACTIVE' && ! empty($cartItems)) {
            $cartFocusBlock = $this->cartFocus->resolve($session, $cartItems, $focusReason, $focusItem);
        }

        // ── Payment block ─────────────────────────────────────────────────
        $paymentBlock = $this->paymentState->forSession($session);

        // ── UI flags ─────────────────────────────────────────────────────
        $uiFlags = $this->buildUiFlags($displayState);

        return [
            'terminal'        => $terminalBlock,
            'session'         => $sessionBlock,
            'store'           => $storeBlock,
            'member'          => $memberBlock,
            'cart_focus'      => $cartFocusBlock,
            'cart'            => $cartBlock,
            'payment'         => $paymentBlock,
            'ui_flags'        => $uiFlags,
        ];
    }

    private function buildUiFlags(string $displayState): array
    {
        return [
            'show_screensaver'       => $displayState === 'IDLE_SCREENSAVER',
            'show_member_panel'      => $displayState === 'MEMBER_NO_CART',
            'show_cart_focus_panel'  => $displayState === 'CART_ACTIVE',
            'show_cart_panel'        => in_array($displayState, ['CART_ACTIVE', 'LOCKED_REVIEW']),
            'show_payment_panel'     => in_array($displayState, ['PAYMENT_PENDING', 'PAYMENT_APPROVED', 'PAYMENT_FAILED']),
            'show_completed_panel'   => $displayState === 'COMPLETED',
        ];
    }
}
