<?php

namespace Modules\EventManagement\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\EventManagement\Services\AccessControlService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to filter inventory data from responses for non-staff users
 *
 * Requirements: 5.1, 5.2, 5.3, 6.4
 * - Customers should not see inventory quantities
 * - Customers should not see remaining ticket counts
 * - Staff should see full inventory data
 */
class FilterInventoryMiddleware
{
    protected AccessControlService $accessControlService;

    public function __construct(AccessControlService $accessControlService)
    {
        $this->accessControlService = $accessControlService;
    }

    /**
     * Handle an incoming request and filter inventory data from response
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only filter JSON responses
        if (! $response instanceof JsonResponse) {
            return $response;
        }

        $user = $request->user();

        // If user is staff, don't filter anything
        if ($user && $this->accessControlService->isStaff($user)) {
            return $response;
        }

        // Filter inventory data from response for non-staff users
        $data = $response->getData(true);
        $filteredData = $this->removeInventoryFields($data);
        $response->setData($filteredData);

        return $response;
    }

    /**
     * Recursively remove inventory fields from data
     *
     * @param  mixed  $data
     * @return mixed
     */
    protected function removeInventoryFields($data)
    {
        if (! is_array($data)) {
            return $data;
        }

        // Fields to remove for customers
        $inventoryFields = [
            'inventory',
            'total_tickets',
            'sold_tickets',
            'remaining_tickets',
            'available_tickets',
            'ticket_count',
            'tickets_sold',
            'tickets_remaining',
            'tickets_available',
            'capacity',
            'total_capacity',
            'sold_count',
            'remaining_count',
        ];

        // Remove inventory fields at current level
        foreach ($inventoryFields as $field) {
            unset($data[$field]);
        }

        // Recursively process nested arrays and objects
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->removeInventoryFields($value);
            }
        }

        return $data;
    }
}
