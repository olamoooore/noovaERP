<?php

namespace App\Contracts;

use Modules\PaymentGateway\Models\PaymentTransaction;

/**
 * Platform seam: the module owning storefront orders provides the completion
 * behavior; PaymentGateway only asks "is a handler bound?". This keeps
 * PaymentGateway from hard-importing a business module while making the
 * storefront_order transaction type fail CLOSED when the handler is absent
 * (it must never fall through to the legacy order_id resolver — different
 * tables share id spaces).
 */
interface StorefrontOrderPaymentCompletion
{
    public function complete(PaymentTransaction $transaction, ?string $paymentMethod = null): void;
}
