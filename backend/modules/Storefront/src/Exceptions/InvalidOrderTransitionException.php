<?php

namespace Modules\Storefront\Exceptions;

use RuntimeException;

/**
 * Thrown when a writer asks for a transition the shared state machine does
 * not allow (FR-ORD-01 / acceptance A-07). Carries full context for logging
 * and for the writer-facing 422 payload — never silently coerced.
 */
final class InvalidOrderTransitionException extends RuntimeException
{
    public function __construct(
        public readonly string $domain,
        public readonly string $from,
        public readonly string $to,
        public readonly array $allowedFrom,
        public readonly ?string $writer = null,
    ) {
        parent::__construct(sprintf(
            '%s transition %s -> %s is not allowed%s (allowed: %s).',
            $domain,
            $from,
            $to,
            $writer ? " for writer '{$writer}'" : '',
            $allowedFrom === [] ? 'none (terminal)' : implode(', ', $allowedFrom),
        ));
    }

    public function context(): array
    {
        return [
            'domain' => $this->domain,
            'from' => $this->from,
            'to' => $this->to,
            'allowed' => $this->allowedFrom,
            'writer' => $this->writer,
        ];
    }
}
