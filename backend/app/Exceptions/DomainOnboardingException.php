<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Domain onboarding rule violations (FR-PLAT-03). Rendered as JSON with a
 * stable machine-readable `code` so both the SuperAdmin console and the
 * tenant settings UI can present the exact reason — same contract shape as
 * Storefront's CheckoutException (message + code + status).
 */
class DomainOnboardingException extends \Exception
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function tenantNotFound(): self
    {
        return new self('Tenant not found.', 'tenant_not_found', 404);
    }

    public static function domainNotFound(): self
    {
        return new self('Domain not found for this tenant.', 'domain_not_found', 404);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], $this->status);
    }
}
