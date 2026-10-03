<?php

namespace App\Platform\Exceptions;

/**
 * Issue #902: a structured report for the platform exception queue.
 *
 * Wraps everything a queued exception must carry so a failure site never has
 * to assemble raw column values itself: the typed exception, the affected
 * document (type + id), the legal entity, severity, a payload snapshot and an
 * optional retry-handler override.
 */
final class ExceptionReport
{
    /**
     * @param  array<string, mixed>  $payload  snapshot of the failure context (document number, amounts, error, …)
     */
    public function __construct(
        public readonly ExceptionType $type,
        public readonly string $message,
        public readonly string|int|null $documentId = null,
        public readonly ?string $documentType = null,
        public readonly ?int $legalEntityId = null,
        public readonly array $payload = [],
        public readonly ?string $severity = null,
        public readonly ?string $retryHandler = null,
    ) {}

    public function module(): string
    {
        return $this->type->module();
    }

    public function severity(): string
    {
        return $this->severity ?? $this->type->defaultSeverity();
    }

    public function ownerRole(): string
    {
        return $this->type->ownerRole()->value;
    }

    public function retryHandler(): ?string
    {
        return $this->retryHandler ?? $this->type->retryHandler();
    }
}
