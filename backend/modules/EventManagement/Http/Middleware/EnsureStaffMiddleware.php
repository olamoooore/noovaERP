<?php

namespace Modules\EventManagement\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Contracts\Routing\ResponseFactory;
use Modules\EventManagement\Services\AccessControlService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to ensure user has staff role
 * 
 * Requirements: 6.4, 6.5, 12.2, 12.3
 * - Only staff can access inventory data
 * - Only staff can access configuration endpoints
 * - Only staff can manage bids
 * - Non-staff users receive 403 Forbidden
 */
class EnsureStaffMiddleware
{
    protected AccessControlService $accessControlService;
    protected ResponseFactory $responseFactory;

    public function __construct(
        AccessControlService $accessControlService,
        ResponseFactory $responseFactory
    ) {
        $this->accessControlService = $accessControlService;
        $this->responseFactory = $responseFactory;
    }

    /**
     * Handle an incoming request and ensure user is staff
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Check if user is authenticated
        if (!$user) {
            return $this->responseFactory->json([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'Authentication required',
                    'request_id' => $request->header('X-Request-ID', uniqid())
                ]
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Check if user is staff
        if (!$this->accessControlService->isStaff($user)) {
            return $this->responseFactory->json([
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Access denied. Staff privileges required.',
                    'request_id' => $request->header('X-Request-ID', uniqid())
                ]
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
