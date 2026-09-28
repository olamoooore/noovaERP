<?php

namespace Modules\Storefront\Exceptions;

final class AccountAuthException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 401,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function make(string $code, string $message, int $status = 401, array $context = []): self
    {
        return new self($code, $message, $status, $context);
    }
}
