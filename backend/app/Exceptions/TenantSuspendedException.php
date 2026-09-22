<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Issue #212: thrown centrally whenever tenancy is initialized for a suspended
 * tenant, covering every tenant-resolution entry point (public storefront,
 * IoT, webhooks, queued jobs), not just authenticated requests.
 */
class TenantSuspendedException extends RuntimeException
{
    public function __construct(
        public readonly string $tenantId,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "Tenant {$tenantId} is suspended.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'This workspace is suspended. Contact the platform administrator.',
            'reason_code' => 'TENANT_SUSPENDED',
        ], 503);
    }
}
