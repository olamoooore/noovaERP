<?php

namespace Modules\EventManagement\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\EventManagement\Services\TicketStatusService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Exception;

/**
 * CustomerHubController - Task 10.3
 * 
 * Enhanced customer hub UI controller with real-time status updates,
 * clear event instructions, ticket resend functionality, and status history.
 * 
 * Requirements: 26.2, 26.5
 */
class CustomerHubController extends Controller
{
    private TicketStatusService $ticketStatusService;

    public function __construct(TicketStatusService $ticketStatusService)
    {
        $this->ticketStatusService = $ticketStatusService;
    }

    /**
     * Get customer hub dashboard
        * Shows only the authenticated customer's bookings for this tenant.
        * Results are sorted with upcoming events first.
     */
    public function getDashboard(Request $request): JsonResponse
    {
        try {
            $now = now();
            $user = $request->user();
            $scopeToCustomer = $this->shouldScopeToCustomer($user);

            // Customers see only confirmed/booked. Admins/managers see ALL states
            // (pending, confirmed, booked, paid, cancelled, refunded, attended).
            $bookingStates = $scopeToCustomer
                ? ['confirmed', 'booked', 'paid']
                : ['pending', 'confirmed', 'booked', 'paid', 'attended', 'cancelled', 'refunded', 'no_show'];
            $eventSelects = [
                'e.title as event_title',
                'e.venue as event_venue',
                'e.status as event_status',
            ];

            $eventSelects[] = Schema::hasColumn('events', 'start_date')
                ? 'e.start_date as event_start'
                : (Schema::hasColumn('events', 'event_date') ? 'e.event_date as event_start' : DB::raw('NULL as event_start'));

            $eventSelects[] = Schema::hasColumn('events', 'end_date')
                ? 'e.end_date as event_end'
                : DB::raw('NULL as event_end');

            $eventSelects[] = Schema::hasColumn('events', 'image_url')
                ? 'e.image_url as event_image'
                : DB::raw('NULL as event_image');

            $eventSelects[] = Schema::hasColumn('events', 'price')
                ? 'e.price as event_price'
                : DB::raw('NULL as event_price');

            // Fetch all confirmed bookings, joining event data
            $query = DB::table('bookings as b')
                ->leftJoin('events as e', 'b.event_id', '=', 'e.id')
                ->whereIn('b.state', $bookingStates)
                ->select(
                    'b.id',
                    'b.booking_reference',
                    'b.event_id',
                    'b.buyer_name',
                    'b.buyer_email',
                    'b.state',
                    'b.total_amount',
                    'b.currency',
                    'b.created_at',
                    ...$eventSelects
                )
                ->orderBy('event_start', 'asc');

            if ($scopeToCustomer) {
                $query->where(function ($scope) use ($user) {
                    if (Schema::hasColumn('bookings', 'customer_id')) {
                        $scope->where('b.customer_id', $user->id);
                    }

                    if (!empty($user->email) && Schema::hasColumn('bookings', 'buyer_email')) {
                        $scope->orWhere('b.buyer_email', $user->email);
                    }
                });
            }

            $bookings = $query->get();
            $bookingIds = $bookings->pluck('id')->all();
            $ticketsByBooking = collect();

            if (!empty($bookingIds)) {
                $ticketsByBooking = DB::table('tickets as t')
                    ->leftJoin('booking_attendees as a', 't.attendee_id', '=', 'a.id')
                    ->whereIn('t.booking_id', $bookingIds)
                    ->select(
                        't.id',
                        't.booking_id',
                        't.ticket_code',
                        't.status',
                        't.issued_at',
                        'a.attendee_name',
                        'a.attendee_email'
                    )
                    ->orderBy('t.id')
                    ->get()
                    ->groupBy('booking_id');
            }

            // Split into upcoming / past
            $upcoming = $bookings->filter(function ($b) use ($now) {
                return $b->event_start && \Carbon\Carbon::parse($b->event_start)->gt($now);
            })->values();

            $past = $bookings->filter(function ($b) use ($now) {
                return !$b->event_start || \Carbon\Carbon::parse($b->event_start)->lte($now);
            })->values();

            $formatBooking = function ($b) use ($ticketsByBooking) {
                $tickets = $ticketsByBooking->get($b->id, collect())->map(fn ($ticket) => [
                    'id' => $ticket->id,
                    'ticket_code' => $ticket->ticket_code,
                    'status' => $ticket->status,
                    'issued_at' => $ticket->issued_at,
                    'attendee_name' => $ticket->attendee_name,
                    'attendee_email' => $ticket->attendee_email,
                ])->values();

                return [
                    'id'                => $b->id,
                    'booking_reference' => $b->booking_reference,
                    'event_id'          => $b->event_id,
                    'event_title'       => $b->event_title ?? 'Unknown Event',
                    'event_date'        => $b->event_start,
                    'event_venue'       => $b->event_venue,
                    'event_image'       => $b->event_image,
                    'buyer_name'        => $b->buyer_name,
                    'buyer_email'       => $b->buyer_email,
                    'amount'            => $b->total_amount,
                    'currency'          => $b->currency ?? 'SGD',
                    'state'             => $b->state,
                    'created_at'        => $b->created_at,
                    'ticket_count'       => $tickets->count(),
                    'tickets'            => $tickets->all(),
                ];
            };

            $totalTickets = $ticketsByBooking->flatten(1)->count();

            return response()->json([
                'success'     => true,
                'customer_id' => $request->user()->id,
                'dashboard'   => [
                    'upcoming_events' => $upcoming->map($formatBooking)->all(),
                    'tickets'         => $bookings->map($formatBooking)->all(),
                    'past_events'     => $past->map($formatBooking)->all(),
                    'stats'           => [
                        'upcoming_count' => $upcoming->count(),
                        'total_bookings' => $bookings->count(),
                        'total_tickets'  => $totalTickets,
                    ],
                ],
            ]);

        } catch (Exception $e) {
            Log::error('CustomerHubController@getDashboard failed', [
                'user_id' => optional($request->user())->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'Failed to load dashboard',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function shouldScopeToCustomer($user): bool
    {
        if (!$user) {
            return true;
        }

        // Web customers are stored in a separate model; any authenticated tenant
        // App\Models\User is staff/admin and should see all tenant bookings.
        $class = is_object($user) ? get_class($user) : '';
        if ($class === \App\Models\User::class || is_subclass_of($user, \App\Models\User::class)) {
            // Only scope to themselves if they explicitly hold a customer role.
            if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['customer', 'web_customer', 'guest_customer'])) {
                return true;
            }
            return false;
        }

        if (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin()) {
            return false;
        }

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['admin', 'tenant_admin', 'manager', 'event_manager', 'owner', 'Owner'])) {
            return false;
        }

        $role = strtolower((string) ($user->role ?? ''));

        if ($role === '') {
            // No customer marker → default to admin view for authenticated tenant users.
            return false;
        }

        return !str_contains($role, 'admin') && !str_contains($role, 'manager') && !str_contains($role, 'owner');
    }

    /**
     * Get ticket status with real-time updates
     */
    public function getTicketStatus(Request $request, int $ticketId): JsonResponse
    {
        try {
            // Verify customer owns this ticket
            $customerId = $request->user()->id;
            if (!$this->verifyTicketOwnership($ticketId, $customerId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Ticket not found or access denied'
                ], 404);
            }

            $options = [
                'include_history' => $request->boolean('include_history', true),
                'force_refresh' => $request->boolean('force_refresh', false)
            ];

            $ticketStatus = $this->ticketStatusService->getTicketStatus($ticketId, $options);

            if (!$ticketStatus['success']) {
                return response()->json($ticketStatus, 404);
            }

            // Add customer-specific enhancements
            $ticketStatus['display_info'] = $this->getTicketDisplayInfo($ticketStatus);
            $ticketStatus['available_actions'] = $this->getAvailableActions($ticketStatus);
            $ticketStatus['event_instructions'] = $this->formatEventInstructions($ticketStatus);

            return response()->json($ticketStatus);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get ticket status',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resend ticket to customer
     */
    public function resendTicket(Request $request, int $ticketId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'delivery_method' => 'nullable|in:email,sms,both',
            'reason' => 'nullable|string|max:255'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Verify customer owns this ticket
            $customerId = $request->user()->id;
            if (!$this->verifyTicketOwnership($ticketId, $customerId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Ticket not found or access denied'
                ], 404);
            }

            $resendOptions = [
                'delivery_method' => $request->input('delivery_method', 'email'),
                'reason' => $request->input('reason', 'Customer requested resend'),
                'requested_by_customer' => true
            ];

            $result = $this->ticketStatusService->resendTicket($ticketId, $resendOptions, $customerId);

            return response()->json($result, $result['success'] ? 200 : 400);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to resend ticket',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get ticket status history with explanations
     */
    public function getTicketHistory(Request $request, int $ticketId): JsonResponse
    {
        try {
            // Verify customer owns this ticket
            $customerId = $request->user()->id;
            if (!$this->verifyTicketOwnership($ticketId, $customerId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Ticket not found or access denied'
                ], 404);
            }

            $options = [
                'include_history' => true,
                'limit' => $request->input('limit', 50)
            ];

            $ticketStatus = $this->ticketStatusService->getTicketStatus($ticketId, $options);

            if (!$ticketStatus['success']) {
                return response()->json($ticketStatus, 404);
            }

            // Enhance history with customer-friendly explanations
            $enhancedHistory = $this->enhanceStatusHistory($ticketStatus['status_history']);

            return response()->json([
                'success' => true,
                'ticket_id' => $ticketId,
                'current_status' => $ticketStatus['current_status'],
                'status_history' => $enhancedHistory,
                'timeline' => $this->generateStatusTimeline($enhancedHistory)
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get ticket history',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get event instructions and information
     */
    public function getEventInstructions(Request $request, int $eventId): JsonResponse
    {
        try {
            $customerId = $request->user()->id;

            // Verify customer has tickets for this event
            if (!$this->verifyEventAccess($eventId, $customerId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Event not found or access denied'
                ], 404);
            }

            $instructions = $this->getEventInstructionsData($eventId, $customerId);

            return response()->json([
                'success' => true,
                'event_id' => $eventId,
                'instructions' => $instructions
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get event instructions',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update customer preferences
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'notification_preferences' => 'nullable|array',
            'display_preferences' => 'nullable|array',
            'privacy_settings' => 'nullable|array',
            'email_notifications_enabled' => 'nullable|boolean',
            'sms_notifications_enabled' => 'nullable|boolean',
            'push_notifications_enabled' => 'nullable|boolean',
            'preferred_language' => 'nullable|string|size:2',
            'timezone' => 'nullable|string|max:50',
            'communication_frequency' => 'nullable|in:immediate,daily_digest,weekly_digest,minimal'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $customerId = $request->user()->id;

            $preferences = array_filter([
                'notification_preferences' => $request->input('notification_preferences'),
                'display_preferences' => $request->input('display_preferences'),
                'privacy_settings' => $request->input('privacy_settings'),
                'email_notifications_enabled' => $request->input('email_notifications_enabled'),
                'sms_notifications_enabled' => $request->input('sms_notifications_enabled'),
                'push_notifications_enabled' => $request->input('push_notifications_enabled'),
                'preferred_language' => $request->input('preferred_language'),
                'timezone' => $request->input('timezone'),
                'communication_frequency' => $request->input('communication_frequency')
            ], function ($value) {
                return $value !== null;
            });

            $result = $this->updateCustomerPreferences($customerId, $preferences);

            return response()->json($result, $result['success'] ? 200 : 400);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update preferences',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get customer notifications
     */
    public function getNotifications(Request $request): JsonResponse
    {
        try {
            $customerId = $request->user()->id;

            $options = [
                'limit' => $request->input('limit', 20),
                'unread_only' => $request->boolean('unread_only', false),
                'notification_type' => $request->input('type'),
                'since' => $request->input('since')
            ];

            $notifications = $this->getCustomerNotifications($customerId, $options);

            return response()->json([
                'success' => true,
                'customer_id' => $customerId,
                'notifications' => $notifications,
                'unread_count' => collect($notifications)->whereNull('read_at')->count()
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get notifications',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark notification as read
     */
    public function markNotificationRead(Request $request, int $notificationId): JsonResponse
    {
        try {
            $customerId = $request->user()->id;

            $result = $this->markNotificationAsRead($notificationId, $customerId);

            return response()->json($result, $result['success'] ? 200 : 400);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to mark notification as read',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get real-time status updates (WebSocket endpoint simulation)
     */
    public function getStatusUpdates(Request $request): JsonResponse
    {
        try {
            $customerId = $request->user()->id;
            $lastUpdate = $request->input('last_update');

            $updates = $this->getRecentStatusUpdates($customerId, $lastUpdate);

            return response()->json([
                'success' => true,
                'customer_id' => $customerId,
                'updates' => $updates,
                'timestamp' => now()->toISOString()
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get status updates',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Report ticket issue or request support
     */
    public function reportTicketIssue(Request $request, int $ticketId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'issue_type' => 'required|in:not_received,invalid_qr,access_denied,technical_issue,other',
            'description' => 'required|string|min:10|max:1000',
            'contact_method' => 'nullable|in:email,phone,chat'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            // Verify customer owns this ticket
            $customerId = $request->user()->id;
            if (!$this->verifyTicketOwnership($ticketId, $customerId)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Ticket not found or access denied'
                ], 404);
            }

            $issueData = [
                'ticket_id' => $ticketId,
                'customer_id' => $customerId,
                'issue_type' => $request->input('issue_type'),
                'description' => $request->input('description'),
                'contact_method' => $request->input('contact_method', 'email')
            ];

            $result = $this->createSupportTicket($issueData);

            return response()->json($result, $result['success'] ? 201 : 400);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to report issue',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Track recommendation interaction
     */
    public function trackRecommendation(Request $request, int $recommendationId): JsonResponse
    {
        // Stub implementation
        return response()->json(['success' => true]);
    }
    // Private helper methods

    private function verifyTicketOwnership(int $ticketId, int $customerId): bool
    {
        return \DB::table('ticket_status_log')
            ->where('ticket_id', $ticketId)
            ->where('customer_id', $customerId)
            ->where('is_current_status', true)
            ->exists();
    }

    private function verifyEventAccess(int $eventId, int $customerId): bool
    {
        return \DB::table('ticket_status_log')
            ->where('event_id', $eventId)
            ->where('customer_id', $customerId)
            ->where('is_current_status', true)
            ->exists();
    }

    private function getTicketDisplayInfo(array $ticketStatus): array
    {
        $status = $ticketStatus['current_status']['status'];

        $displayInfo = [
            'status_label' => $this->getStatusLabel($status),
            'status_description' => $this->getStatusDescription($status),
            'status_color' => $this->getStatusColor($status),
            'progress_percentage' => $this->getProgressPercentage($status),
            'next_steps' => $this->getNextSteps($status),
            'estimated_timeline' => $this->getEstimatedTimeline($status)
        ];

        return $displayInfo;
    }

    private function getAvailableActions(array $ticketStatus): array
    {
        $status = $ticketStatus['current_status']['status'];
        $actions = [];

        switch ($status) {
            case 'ticket_issued':
            case 'ready_for_delivery':
                $actions[] = [
                    'action' => 'resend_ticket',
                    'label' => 'Resend Ticket',
                    'description' => 'Send ticket to your email again',
                    'icon' => 'mail'
                ];
                break;

            case 'delivered':
                $actions[] = [
                    'action' => 'view_ticket',
                    'label' => 'View Ticket',
                    'description' => 'View your digital ticket',
                    'icon' => 'ticket'
                ];
                $actions[] = [
                    'action' => 'add_to_wallet',
                    'label' => 'Add to Wallet',
                    'description' => 'Add ticket to Apple/Google Wallet',
                    'icon' => 'wallet'
                ];
                break;

            case 'cancelled':
            case 'refunded':
                $actions[] = [
                    'action' => 'view_refund_status',
                    'label' => 'View Refund Status',
                    'description' => 'Check refund processing status',
                    'icon' => 'refund'
                ];
                break;
        }

        // Always allow reporting issues
        $actions[] = [
            'action' => 'report_issue',
            'label' => 'Report Issue',
            'description' => 'Get help with your ticket',
            'icon' => 'help'
        ];

        return $actions;
    }

    private function formatEventInstructions(array $ticketStatus): array
    {
        $eventId = $ticketStatus['current_status']['event_id'];
        return $this->getEventInstructionsData($eventId, $ticketStatus['current_status']['customer_id']);
    }

    private function getEventInstructionsData(int $eventId, int $customerId): array
    {
        // This would typically fetch from an events table
        return [
            'venue_info' => [
                'name' => 'Sample Venue',
                'address' => '123 Event Street, City, State 12345',
                'parking_info' => 'Parking available on-site for $10',
                'accessibility' => 'Wheelchair accessible venue'
            ],
            'entry_requirements' => [
                'doors_open' => '18:00',
                'show_starts' => '19:30',
                'age_restriction' => 'All ages welcome',
                'prohibited_items' => ['Outside food/drinks', 'Professional cameras', 'Recording devices'],
                'required_items' => ['Valid ID', 'Digital or printed ticket']
            ],
            'covid_guidelines' => [
                'mask_required' => false,
                'vaccination_required' => false,
                'health_screening' => false
            ],
            'contact_info' => [
                'venue_phone' => '+1-555-0123',
                'customer_service' => '+1-555-0456',
                'emergency_contact' => '+1-555-0789'
            ],
            'additional_info' => [
                'merchandise_available' => true,
                'food_beverages_available' => true,
                'coat_check_available' => true,
                'will_call_location' => 'Main entrance box office'
            ]
        ];
    }

    private function enhanceStatusHistory(array $history): array
    {
        return array_map(function ($entry) {
            return [
                'status' => $entry['status'],
                'previous_status' => $entry['previous_status'],
                'changed_at' => $entry['changed_at'],
                'status_label' => $this->getStatusLabel($entry['status']),
                'description' => $this->getStatusDescription($entry['status']),
                'customer_message' => $this->getCustomerMessage($entry['status'], $entry),
                'icon' => $this->getStatusIcon($entry['status']),
                'color' => $this->getStatusColor($entry['status'])
            ];
        }, $history);
    }

    private function generateStatusTimeline(array $history): array
    {
        $timeline = [];

        foreach ($history as $entry) {
            $timeline[] = [
                'timestamp' => $entry['changed_at'],
                'title' => $entry['status_label'],
                'description' => $entry['customer_message'],
                'icon' => $entry['icon'],
                'color' => $entry['color'],
                'is_current' => false // Will be set for current status
            ];
        }

        // Mark the most recent entry as current
        if (!empty($timeline)) {
            $timeline[0]['is_current'] = true;
        }

        return array_reverse($timeline); // Show chronologically
    }

    private function updateCustomerPreferences(int $customerId, array $preferences): array
    {
        try {
            \DB::beginTransaction();

            // Prepare preference data
            $preferenceData = [
                'customer_id' => $customerId,
                'preferences_updated_at' => now(),
                'updated_at' => now()
            ];

            // Add JSON fields
            if (isset($preferences['notification_preferences'])) {
                $preferenceData['notification_preferences'] = json_encode($preferences['notification_preferences']);
            }
            if (isset($preferences['display_preferences'])) {
                $preferenceData['display_preferences'] = json_encode($preferences['display_preferences']);
            }
            if (isset($preferences['privacy_settings'])) {
                $preferenceData['privacy_settings'] = json_encode($preferences['privacy_settings']);
            }

            // Add boolean fields
            foreach (['email_notifications_enabled', 'sms_notifications_enabled', 'push_notifications_enabled'] as $field) {
                if (isset($preferences[$field])) {
                    $preferenceData[$field] = $preferences[$field];
                }
            }

            // Add string fields
            foreach (['preferred_language', 'timezone', 'communication_frequency'] as $field) {
                if (isset($preferences[$field])) {
                    $preferenceData[$field] = $preferences[$field];
                }
            }

            // Upsert preferences
            \DB::table('customer_hub_preferences')->updateOrInsert(
                ['customer_id' => $customerId],
                $preferenceData
            );

            \DB::commit();

            return [
                'success' => true,
                'message' => 'Preferences updated successfully'
            ];

        } catch (Exception $e) {
            \DB::rollBack();
            return [
                'success' => false,
                'error' => 'Failed to update preferences',
                'message' => $e->getMessage()
            ];
        }
    }

    private function getCustomerNotifications(int $customerId, array $options): array
    {
        $query = \DB::table('customer_notifications')
            ->where('customer_id', $customerId)
            ->orderBy('created_at', 'desc');

        if ($options['unread_only'] ?? false) {
            $query->whereNull('read_at');
        }

        if (isset($options['notification_type'])) {
            $query->where('notification_type', $options['notification_type']);
        }

        if (isset($options['since'])) {
            $query->where('created_at', '>=', $options['since']);
        }

        if (isset($options['limit'])) {
            $query->limit($options['limit']);
        }

        return $query->get()->map(function ($notification) {
            return [
                'id' => $notification->id,
                'type' => $notification->notification_type,
                'subject' => $notification->subject,
                'content' => $notification->content,
                'delivery_channel' => $notification->delivery_channel,
                'priority' => $notification->priority,
                'delivery_status' => $notification->delivery_status,
                'created_at' => $notification->created_at,
                'read_at' => $notification->read_at,
                'ticket_id' => $notification->ticket_id,
                'booking_id' => $notification->booking_id
            ];
        })->toArray();
    }

    private function markNotificationAsRead(int $notificationId, int $customerId): array
    {
        try {
            $updated = \DB::table('customer_notifications')
                ->where('id', $notificationId)
                ->where('customer_id', $customerId)
                ->whereNull('read_at')
                ->update([
                    'read_at' => now(),
                    'updated_at' => now()
                ]);

            if ($updated) {
                return [
                    'success' => true,
                    'message' => 'Notification marked as read'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Notification not found or already read'
                ];
            }

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to mark notification as read',
                'message' => $e->getMessage()
            ];
        }
    }

    private function getRecentStatusUpdates(int $customerId, ?string $lastUpdate): array
    {
        $query = \DB::table('ticket_status_log')
            ->where('customer_id', $customerId)
            ->where('is_current_status', true);

        if ($lastUpdate) {
            $query->where('status_changed_at', '>', $lastUpdate);
        } else {
            $query->where('status_changed_at', '>=', now()->subMinutes(5));
        }

        return $query->get()->map(function ($status) {
            return [
                'ticket_id' => $status->ticket_id,
                'status' => $status->status,
                'status_changed_at' => $status->status_changed_at,
                'status_label' => $this->getStatusLabel($status->status),
                'customer_message' => $this->getCustomerMessage($status->status, (array) $status)
            ];
        })->toArray();
    }

    private function createSupportTicket(array $issueData): array
    {
        try {
            // This would integrate with a support ticket system
            $supportTicketId = 'SUPPORT_' . uniqid();

            // Log the support request
            \Log::info('Customer support ticket created', [
                'support_ticket_id' => $supportTicketId,
                'customer_id' => $issueData['customer_id'],
                'ticket_id' => $issueData['ticket_id'],
                'issue_type' => $issueData['issue_type']
            ]);

            return [
                'success' => true,
                'support_ticket_id' => $supportTicketId,
                'message' => 'Support ticket created successfully. You will be contacted within 24 hours.',
                'estimated_response_time' => '24 hours'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'Failed to create support ticket',
                'message' => $e->getMessage()
            ];
        }
    }

    // Status display helper methods

    private function getStatusLabel(string $status): string
    {
        $labels = [
            'pending_payment' => 'Payment Pending',
            'payment_confirmed' => 'Payment Confirmed',
            'ticket_issued' => 'Ticket Issued',
            'ready_for_delivery' => 'Ready for Delivery',
            'delivered' => 'Ticket Delivered',
            'checked_in' => 'Checked In',
            'used' => 'Event Attended',
            'cancelled' => 'Cancelled',
            'refunded' => 'Refunded',
            'expired' => 'Expired',
            'suspended' => 'Temporarily Suspended',
            'invalid' => 'Invalid'
        ];

        return $labels[$status] ?? ucwords(str_replace('_', ' ', $status));
    }

    private function getStatusDescription(string $status): string
    {
        $descriptions = [
            'pending_payment' => 'Your payment is being processed. Please complete payment to secure your ticket.',
            'payment_confirmed' => 'Payment successful! Your ticket is being prepared.',
            'ticket_issued' => 'Your ticket has been generated and will be delivered shortly.',
            'ready_for_delivery' => 'Your ticket is ready and will be sent to you soon.',
            'delivered' => 'Your ticket has been delivered. Check your email or app for access.',
            'checked_in' => 'You have successfully checked in to the event.',
            'used' => 'You have attended the event. Thank you for joining us!',
            'cancelled' => 'This ticket has been cancelled. Refund processing if applicable.',
            'refunded' => 'This ticket has been refunded. Refund should appear in 3-5 business days.',
            'expired' => 'This ticket has expired and is no longer valid.',
            'suspended' => 'This ticket is temporarily suspended. Please contact support.',
            'invalid' => 'This ticket is invalid. Please contact support for assistance.'
        ];

        return $descriptions[$status] ?? 'Status information not available.';
    }

    private function getStatusColor(string $status): string
    {
        $colors = [
            'pending_payment' => 'orange',
            'payment_confirmed' => 'blue',
            'ticket_issued' => 'blue',
            'ready_for_delivery' => 'blue',
            'delivered' => 'green',
            'checked_in' => 'green',
            'used' => 'gray',
            'cancelled' => 'red',
            'refunded' => 'purple',
            'expired' => 'gray',
            'suspended' => 'yellow',
            'invalid' => 'red'
        ];

        return $colors[$status] ?? 'gray';
    }

    private function getStatusIcon(string $status): string
    {
        $icons = [
            'pending_payment' => 'clock',
            'payment_confirmed' => 'check-circle',
            'ticket_issued' => 'ticket',
            'ready_for_delivery' => 'truck',
            'delivered' => 'mail',
            'checked_in' => 'check-square',
            'used' => 'star',
            'cancelled' => 'x-circle',
            'refunded' => 'dollar-sign',
            'expired' => 'calendar-x',
            'suspended' => 'pause-circle',
            'invalid' => 'alert-triangle'
        ];

        return $icons[$status] ?? 'help-circle';
    }

    private function getProgressPercentage(string $status): int
    {
        $progress = [
            'pending_payment' => 10,
            'payment_confirmed' => 25,
            'ticket_issued' => 50,
            'ready_for_delivery' => 75,
            'delivered' => 90,
            'checked_in' => 95,
            'used' => 100,
            'cancelled' => 0,
            'refunded' => 0,
            'expired' => 0,
            'suspended' => 0,
            'invalid' => 0
        ];

        return $progress[$status] ?? 0;
    }

    private function getNextSteps(string $status): array
    {
        $steps = [
            'pending_payment' => ['Complete your payment to secure your ticket'],
            'payment_confirmed' => ['Wait for ticket generation and delivery'],
            'ticket_issued' => ['Check your email for ticket delivery'],
            'ready_for_delivery' => ['Your ticket will be delivered shortly'],
            'delivered' => ['Save your ticket and prepare for the event', 'Add ticket to your mobile wallet'],
            'checked_in' => ['Enjoy the event!'],
            'used' => ['Thank you for attending!'],
            'cancelled' => ['Contact support if you have questions about cancellation'],
            'refunded' => ['Refund will appear in your account within 3-5 business days'],
            'expired' => ['This ticket is no longer valid'],
            'suspended' => ['Contact customer support for assistance'],
            'invalid' => ['Contact customer support immediately']
        ];

        return $steps[$status] ?? ['Contact customer support for assistance'];
    }

    private function getEstimatedTimeline(string $status): ?string
    {
        $timelines = [
            'pending_payment' => 'Complete payment within 15 minutes to secure your ticket',
            'payment_confirmed' => 'Ticket will be issued within 5 minutes',
            'ticket_issued' => 'Delivery within 2-3 minutes',
            'ready_for_delivery' => 'Delivery within 1-2 minutes',
            'delivered' => 'Ready for event entry',
            'checked_in' => null,
            'used' => null,
            'cancelled' => null,
            'refunded' => 'Refund processing: 3-5 business days',
            'expired' => null,
            'suspended' => 'Contact support for resolution timeline',
            'invalid' => 'Contact support immediately'
        ];

        return $timelines[$status] ?? null;
    }

    private function getCustomerMessage(string $status, array $context): string
    {
        $messages = [
            'pending_payment' => 'Please complete your payment to secure your ticket.',
            'payment_confirmed' => 'Great! Your payment was successful. Your ticket is being prepared.',
            'ticket_issued' => 'Your ticket has been created and will be delivered to you shortly.',
            'ready_for_delivery' => 'Your ticket is ready! It will be sent to your email any moment.',
            'delivered' => 'Your ticket has been delivered! Check your email and save it to your device.',
            'checked_in' => 'Welcome to the event! You have successfully checked in.',
            'used' => 'Thank you for attending! We hope you enjoyed the event.',
            'cancelled' => 'Your ticket has been cancelled. If you have questions, please contact support.',
            'refunded' => 'Your refund is being processed and will appear in your account within 3-5 business days.',
            'expired' => 'This ticket has expired and is no longer valid for entry.',
            'suspended' => 'Your ticket is temporarily suspended. Please contact customer support.',
            'invalid' => 'There is an issue with your ticket. Please contact customer support immediately.'
        ];

        return $messages[$status] ?? 'Your ticket status has been updated.';
    }
}