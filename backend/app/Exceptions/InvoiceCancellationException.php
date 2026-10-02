<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Issue #681: a request to cancel an invoice was refused before any local
 * mutation — wrong document status, live payments / posted credit notes, or
 * the e-invoice lifecycle guard (LHDN) declining the cancellation.
 *
 * The controller maps this back to the exact JSON/status the refusal carries,
 * so the e-invoice guard's credit-note pointer and 422 semantics survive the
 * service boundary.
 */
class InvoiceCancellationException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        string $message,
        private readonly int $status = 422,
        private readonly array $payload = [],
    ) {
        parent::__construct($message);
    }

    public static function fromJsonResponse(JsonResponse $response): self
    {
        $payload = json_decode((string) $response->getContent(), true);
        $payload = is_array($payload) ? $payload : [];

        return new self(
            (string) ($payload['message'] ?? 'Invoice cancellation was refused'),
            $response->getStatusCode(),
            $payload,
        );
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
