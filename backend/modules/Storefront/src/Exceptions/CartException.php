<?php

namespace Modules\Storefront\Exceptions;

use RuntimeException;

/**
 * Cart domain rejection with a machine-readable code and HTTP status.
 * The public API turns these into {message, code} 422/404 payloads —
 * validation lives server-side only (FR-CO-03: client values are never
 * trusted; the cart refuses what the ERP says is unpurchasable).
 */
final class CartException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function make(string $code, string $message, int $status = 422, array $context = []): self
    {
        return new self($code, $message, $status, $context);
    }
}
