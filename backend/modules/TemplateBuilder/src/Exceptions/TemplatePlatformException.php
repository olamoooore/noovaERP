<?php

declare(strict_types=1);

namespace Modules\TemplateBuilder\Exceptions;

use Modules\TemplateBuilder\Platform\TemplateErrorCode;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Thrown for any ADR-004 template-platform failure surfaced through the
 * canonical {@see TemplateErrorCode} catalogue.
 *
 * Implements HttpExceptionInterface so the global API exception renderer in
 * bootstrap/app.php resolves the canonical HTTP status automatically, and the
 * canonical error code + retryability + audit level are emitted in the JSON
 * payload.
 */
class TemplatePlatformException extends RuntimeException implements HttpExceptionInterface
{
    /** @var array<string,mixed> */
    private array $context;

    public function __construct(
        private readonly TemplateErrorCode $errorCode,
        ?string $detail = null,
        array $context = [],
        ?\Throwable $previous = null,
    ) {
        $this->context = $context;

        $message = $errorCode->safeMessage();
        if ($detail !== null && $detail !== '') {
            $message .= ' '.$detail;
        }

        parent::__construct($message, 0, $previous);
    }

    public static function fromCode(TemplateErrorCode $code, ?string $detail = null, array $context = []): self
    {
        return new self($code, $detail, $context);
    }

    public function errorCode(): TemplateErrorCode
    {
        return $this->errorCode;
    }

    public function canonicalCode(): string
    {
        return $this->errorCode->value;
    }

    public function getStatusCode(): int
    {
        return $this->errorCode->httpStatus();
    }

    public function getHeaders(): array
    {
        return [];
    }

    public function retryable(): bool
    {
        return $this->errorCode->retryable();
    }

    public function auditLevel(): string
    {
        return $this->errorCode->auditLevel();
    }

    /** @return array<string,mixed> */
    public function context(): array
    {
        return $this->context;
    }

    /** Payload to merge into the API JSON error response. */
    public function responsePayload(): array
    {
        return [
            'error_code' => $this->canonicalCode(),
            'retryable' => $this->retryable(),
            'ui_action' => $this->errorCode->uiAction(),
            'context' => empty($this->context) ? null : $this->context,
        ];
    }
}
