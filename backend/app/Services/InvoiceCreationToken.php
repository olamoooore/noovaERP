<?php

namespace App\Services;

/**
 * One-shot token granting access to InvoiceService::createInvoiceInternal().
 *
 * Replaces the debug_backtrace heuristic guard with structural enforcement:
 * only code that can call InvoiceService::issueCreationToken() can obtain this,
 * and each token is consumed exactly once.
 */
final class InvoiceCreationToken
{
    private bool $consumed = false;

    public function __construct(
        private readonly InvoiceService $issuer,
    ) {}

    /**
     * Consume the token. Throws if already used.
     *
     * @throws \LogicException
     */
    public function consume(): void
    {
        if ($this->consumed) {
            throw new \LogicException(
                'InvoiceCreationToken has already been consumed. '.
                'Each token allows exactly one invoice creation.'
            );
        }

        $this->consumed = true;
    }

    public function isConsumed(): bool
    {
        return $this->consumed;
    }
}
