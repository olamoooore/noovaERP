<?php

namespace Modules\EventManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\EventManagement\Http\Requests\CreateEventRequest;
use Modules\EventManagement\Http\Requests\UpdateEventRequest;
use Modules\EventManagement\Http\Requests\UpdateCapacityRequest;
use Modules\EventManagement\Services\EventService;
use Modules\EventManagement\Services\EventResponseTransformer;
use Modules\EventManagement\Services\AccessControlService;
use Modules\EventManagement\Exceptions\FeatureNotEnabledException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class EventController extends Controller
{
    protected EventService $eventService;
    protected EventResponseTransformer $responseTransformer;
    protected AccessControlService $accessControlService;

    public function __construct(
        EventService $eventService,
        EventResponseTransformer $responseTransformer,
        AccessControlService $accessControlService
    ) {
        $this->eventService = $eventService;
        $this->responseTransformer = $responseTransformer;
        $this->accessControlService = $accessControlService;
    }

    /**
     * Display a listing of events for admin
     * Requirements: 7.1 - REST endpoints under /api/v1 namespace
     * Requirements: 1.1, 3.1, 3.2 - Return events with role-based response transformation
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Get all events (including drafts) for admin view
            $events = $this->eventService->getAllEvents($request->query());
            
            // Transform events based on user role
            $transformedEvents = $this->responseTransformer->transformCollection($events);
            
            return response()->json([
                'data' => $transformedEvents,
                'message' => 'Events retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Display a listing of public events
     * Requirements: 7.1 - Public event listing endpoints
     * Requirements: 5.1, 5.2, 5.3 - Customer view without inventory data
     */
    public function publicIndex(): JsonResponse
    {
        try {
            $events = $this->eventService->getPublicEvents();
            
            // Always use customer response for public endpoints
            $transformedEvents = $events->map(function ($event) {
                return $this->responseTransformer->toCustomerResponse($event);
            });
            
            return response()->json([
                'data' => $transformedEvents,
                'message' => 'Public events retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Store a newly created event
     * Requirements: 7.1, 7.5 - CRUD endpoints with proper validation
     * Requirements: 11.1, 11.2, 11.3 - Validate event type, acquisition mode, sale window
     */
    public function store(CreateEventRequest $request): JsonResponse
    {
        try {
            $event = $this->eventService->createEvent($request->validated());
            
            // Transform response based on user role
            $transformedEvent = $this->responseTransformer->transform($event);
            
            return response()->json([
                'data' => $transformedEvent,
                'message' => 'Event created successfully'
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Display the specified event
     * Requirements: 7.1 - Event detail endpoints
     * Requirements: 6.1, 6.2, 6.3 - Staff view includes inventory data
     */
    public function show(int $id): JsonResponse
    {
        try {
            $event = $this->eventService->getEventById($id);
            
            // Transform response based on user role
            $transformedEvent = $this->responseTransformer->transform($event);
            
            return response()->json([
                'data' => $transformedEvent,
                'message' => 'Event retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Display the specified public event
     * Requirements: 7.1 - Public event detail endpoints
     * Requirements: 5.1, 5.2, 5.3 - Customer view without inventory data
     */
    public function publicShow(int $id): JsonResponse
    {
        try {
            $event = $this->eventService->getEventById($id);
            
            // Only show published events to public
            if (!$event->isPublished()) {
                return response()->json([
                    'error' => [
                        'code' => 'EVENT_NOT_FOUND',
                        'message' => 'Event not found or not available',
                        'request_id' => request()->header('X-Request-ID', uniqid())
                    ]
                ], Response::HTTP_NOT_FOUND);
            }
            
            // Always use customer response for public endpoints
            $transformedEvent = $this->responseTransformer->toCustomerResponse($event);
            
            return response()->json([
                'data' => $transformedEvent,
                'message' => 'Event retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Update the specified event
     * Requirements: 7.1, 7.5 - CRUD endpoints with proper validation
     * Requirements: 11.1, 11.2, 11.3 - Validate event configuration updates
     */
    public function update(UpdateEventRequest $request, int $id): JsonResponse
    {
        try {
            $event = $this->eventService->updateEvent($id, $request->validated());
            
            // Transform response based on user role
            $transformedEvent = $this->responseTransformer->transform($event);
            
            return response()->json([
                'data' => $transformedEvent,
                'message' => 'Event updated successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Publish the specified event
     * Requirements: 7.1 - Event publishing endpoint
     */
    public function publish(int $id): JsonResponse
    {
        try {
            $event = $this->eventService->publishEvent($id);
            
            // Transform response based on user role
            $transformedEvent = $this->responseTransformer->transform($event);
            
            return response()->json([
                'data' => $transformedEvent,
                'message' => 'Event published successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Update event capacity
     * Requirements: 7.1 - Capacity management endpoint
     */
    public function updateCapacity(UpdateCapacityRequest $request, int $id): JsonResponse
    {
        try {
            $event = $this->eventService->updateCapacity($id, $request->validated()['capacity']);
            
            // Transform response based on user role
            $transformedEvent = $this->responseTransformer->transform($event);
            
            return response()->json([
                'data' => $transformedEvent,
                'message' => 'Event capacity updated successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Check event availability
     * Requirements: 7.1 - Availability checking endpoint
     */
    public function checkAvailability(Request $request, int $id): JsonResponse
    {
        try {
            $request->validate([
                'requested_tickets' => ['required', 'integer', 'min:1']
            ]);

            $available = $this->eventService->checkAvailability($id, $request->requested_tickets);
            
            return response()->json([
                'data' => [
                    'available' => $available,
                    'event_id' => $id,
                    'requested_tickets' => $request->requested_tickets
                ],
                'message' => 'Availability checked successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Remove the specified event
     * Requirements: 7.1 - CRUD endpoints
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            // Note: In a real system, we might soft delete or archive instead
            $this->eventService->deleteEvent($id);
            
            return response()->json([
                'message' => 'Event deleted successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Handle exceptions and return consistent error responses
     * Requirements: 7.5 - Consistent error responses with proper HTTP status codes
     * Requirements: 11.4 - Descriptive validation error messages
     */
    protected function handleException(\Exception $e): JsonResponse
    {
        $requestId = request()->header('X-Request-ID', uniqid());

        if ($e instanceof FeatureNotEnabledException) {
            return response()->json([
                'error' => [
                    'code' => 'FEATURE_NOT_ENABLED',
                    'message' => $e->getMessage(),
                    'request_id' => $requestId
                ]
            ], Response::HTTP_FORBIDDEN);
        }

        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'error' => [
                    'code' => 'EVENT_NOT_FOUND',
                    'message' => 'Event not found',
                    'request_id' => $requestId
                ]
            ], Response::HTTP_NOT_FOUND);
        }

        if ($e instanceof \InvalidArgumentException) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_ARGUMENT',
                    'message' => $e->getMessage(),
                    'request_id' => $requestId
                ]
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($e instanceof \Illuminate\Validation\ValidationException) {
            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Event configuration is invalid',
                    'validation_errors' => $e->errors(),
                    'request_id' => $requestId
                ]
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Log unexpected errors
        \Log::error('Event API Error', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'request_id' => $requestId
        ]);

        return response()->json([
            'error' => [
                'code' => 'INTERNAL_SERVER_ERROR',
                'message' => 'An unexpected error occurred',
                'request_id' => $requestId
            ]
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}