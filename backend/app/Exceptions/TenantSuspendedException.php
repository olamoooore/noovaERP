<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issue #212: thrown centrally whenever tenancy is initialized for a suspended
 * tenant, covering every tenant-resolution entry point (public storefront,
 * IoT, webhooks, queued jobs), not just authenticated requests.
 *
 * FR-PLAT-03 (P5): the response is a genuine maintenance response, not a
 * bare error — browsers navigating a suspended tenant's public shop get a
 * styled 503 page (generic copy, zero tenant data), while API/JSON callers
 * keep the exact Issue #212 payload. Both carry Retry-After so upstream
 * caches/monitors back off honestly.
 */
class TenantSuspendedException extends RuntimeException
{
    /** Seconds a client should wait before retrying. */
    public const RETRY_AFTER_SECONDS = 3600;

    public function __construct(
        public readonly string $tenantId,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "Tenant {$tenantId} is suspended.");
    }

    public function render(Request $request): Response
    {
        // Request::expectsHtml() does not exist on Illuminate\Http\Request;
        // browser navigations advertise text/html first, API clients do not.
        $accept = strtolower($request->header('Accept', ''));
        $wantsHtml = str_contains($accept, 'text/html') || str_contains($accept, 'application/xhtml+xml');

        if ($wantsHtml) {
            return response()
                ->view('errors.tenant-suspended', [], 503)
                ->header('Retry-After', (string) self::RETRY_AFTER_SECONDS);
        }

        return response()
            ->json([
                'message' => 'This workspace is suspended. Contact the platform administrator.',
                'reason_code' => 'TENANT_SUSPENDED',
            ], 503)
            ->header('Retry-After', (string) self::RETRY_AFTER_SECONDS);
    }
}
