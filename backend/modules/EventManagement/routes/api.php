<?php

use Illuminate\Support\Facades\Route;
use Modules\EventManagement\Http\Controllers\EventController;
use Modules\EventManagement\Http\Controllers\BookingController;
use Modules\EventManagement\Http\Controllers\TicketController;
use Modules\EventManagement\Http\Controllers\AnalyticsController;
use Modules\EventManagement\Http\Controllers\FraudDetectionController;
use Modules\EventManagement\Http\Controllers\AntiAbuseController;
use Modules\EventManagement\Http\Controllers\ManualReviewController;
use Modules\EventManagement\Http\Controllers\OperationalCheckInController;
use Modules\EventManagement\Http\Controllers\EventOperationsController;
use Modules\EventManagement\Http\Controllers\StaffController;
use Modules\EventManagement\Http\Controllers\IncidentController;
use Modules\EventManagement\Http\Controllers\IdentityVerificationController;
use Modules\EventManagement\Http\Controllers\PurchaseController;
use Modules\EventManagement\Http\Controllers\BiddingController;
use Modules\EventManagement\Http\Controllers\EventQueueController;
use Modules\EventManagement\Http\Controllers\WaitingRoomController;

/*
|--------------------------------------------------------------------------
| Event Management API Routes
|--------------------------------------------------------------------------
|
| Here are the API routes for the Event Management module.
| All routes are protected by the feature flag middleware and RBAC.
| Requirements: 7.1, 7.2, 7.3 - REST endpoints with RBAC and rate limiting
|
*/

// Admin/Authenticated API Routes
Route::prefix('event-management')
    ->middleware(['auth:sanctum', 'request.validation', 'entitlement:event_management', 'rate_limit:admin_api'])
    ->group(function () {

        // Event management routes
        Route::prefix('events')->group(function () {
            Route::get('/', [EventController::class, 'index'])
                ->middleware('rbac.event_management:events.view');

            Route::post('/', [EventController::class, 'store'])
                ->middleware('rbac.event_management:events.create');

            Route::get('/{id}', [EventController::class, 'show'])
                ->middleware('rbac.event_management:events.view');

            Route::put('/{id}', [EventController::class, 'update'])
                ->middleware('rbac.event_management:events.update');

            Route::delete('/{id}', [EventController::class, 'destroy'])
                ->middleware('rbac.event_management:events.delete');

            Route::post('/{id}/publish', [EventController::class, 'publish'])
                ->middleware('rbac.event_management:events.publish');

            Route::put('/{id}/capacity', [EventController::class, 'updateCapacity'])
                ->middleware('rbac.event_management:events.update');

            Route::get('/{id}/availability', [EventController::class, 'checkAvailability'])
                ->middleware('rbac.event_management:events.view');

            Route::get('/{id}/statistics', [EventController::class, 'statistics'])
                ->middleware('rbac.event_management:events.view');
        });

        // Purchase routes (Phase 4)
        // Requirements: 3.4, 4.4, 8.1, 8.2, 8.3
        Route::prefix('purchase')->group(function () {
            Route::post('/tickets', [PurchaseController::class, 'purchaseTickets'])
                ->middleware(['rbac.event_management:bookings.create', 'rate_limit:booking_creation']);
        });

        // Bidding routes (Phase 4)
        // Requirements: 3.5, 4.5, 8.1, 8.2, 8.3
        Route::prefix('bidding')->group(function () {
            Route::post('/submit', [BiddingController::class, 'submitBid'])
                ->middleware(['rbac.event_management:bookings.create', 'rate_limit:booking_creation']);

            Route::get('/events/{eventId}', [BiddingController::class, 'getBids'])
                ->middleware('rbac.event_management');

            // Bid management routes (staff/admin only)
            Route::post('/{bidId}/{action}', [BiddingController::class, 'handleBidAction'])
                ->middleware('rbac.event_management')
                ->where('action', 'accept|reject');

            Route::get('/{bidId}', [BiddingController::class, 'getBidDetails'])
                ->middleware('rbac.event_management');

            Route::post('/bulk-process', [BiddingController::class, 'bulkProcessBids'])
                ->middleware('rbac.event_management');
        });

        // Booking management routes
        Route::prefix('bookings')->group(function () {
            Route::get('/', [BookingController::class, 'index'])
                ->middleware('rbac.event_management:bookings.view');

            Route::post('/', [BookingController::class, 'store'])
                ->middleware(['rbac.event_management:bookings.create', 'rate_limit:booking_creation']);

            Route::get('/{id}', [BookingController::class, 'show'])
                ->middleware('rbac.event_management:bookings.view');

            Route::post('/{id}/initiate-payment', [BookingController::class, 'initiatePayment'])
                ->middleware('rbac.event_management:bookings.manage');

            Route::post('/{id}/confirm', [BookingController::class, 'confirm'])
                ->middleware('rbac.event_management:bookings.manage');

            Route::post('/{id}/complete', [BookingController::class, 'complete'])
                ->middleware('rbac.event_management:bookings.manage');

            Route::post('/{id}/cancel', [BookingController::class, 'cancel'])
                ->middleware('rbac.event_management:bookings.cancel');

            Route::get('/{id}/state-transitions', [BookingController::class, 'getStateTransitions'])
                ->middleware('rbac.event_management:bookings.view');

            Route::post('/check-availability', [BookingController::class, 'checkAvailability'])
                ->middleware('rbac.event_management:bookings.view');
        });

        // Ticket management routes
        Route::prefix('tickets')->group(function () {
            Route::get('/', [TicketController::class, 'index'])
                ->middleware('rbac.event_management:tickets.view');

            Route::get('/{ticketCode}', [TicketController::class, 'show'])
                ->middleware('rbac.event_management:tickets.view');

            Route::post('/validate', [TicketController::class, 'validate'])
                ->middleware(['rbac.event_management:tickets.validate', 'rate_limit:ticket_validation']);

            Route::post('/{ticketCode}/mark-used', [TicketController::class, 'markUsed'])
                ->middleware('rbac.event_management:tickets.validate');

            Route::post('/reissue', [TicketController::class, 'reissue'])
                ->middleware('rbac.event_management:tickets.reissue');

            Route::get('/{ticketCode}/qr-code', [TicketController::class, 'qrCode'])
                ->middleware('rbac.event_management:tickets.view');

            Route::get('/event/{eventId}/statistics', [TicketController::class, 'statistics'])
                ->middleware('rbac.event_management:tickets.view');
        });

        // Analytics routes (Phase 2) - Admin only access
        Route::prefix('analytics')->middleware('rbac.event_management')->group(function () {
            Route::get('/dashboard', [AnalyticsController::class, 'getDashboardSummary'])
                ->middleware('rate_limit:analytics');

            Route::get('/events/{eventId}/kpis', [AnalyticsController::class, 'getEventKPIs'])
                ->middleware('rate_limit:analytics');

            Route::get('/events/{eventId}/ticket-performance', [AnalyticsController::class, 'getTicketTypePerformance'])
                ->middleware('rate_limit:analytics');

            Route::get('/events/{eventId}/sales-velocity', [AnalyticsController::class, 'getSalesVelocity'])
                ->middleware('rate_limit:analytics');

            Route::get('/events/{eventId}/repeat-attendees', [AnalyticsController::class, 'getRepeatAttendees'])
                ->middleware('rate_limit:analytics');

            Route::post('/reports/generate', [AnalyticsController::class, 'generateReport'])
                ->middleware('rate_limit:analytics');

            Route::post('/reports/export', [AnalyticsController::class, 'exportReport'])
                ->middleware('rate_limit:analytics');

            Route::get('/trends/sales', [AnalyticsController::class, 'getSalesTrends'])
                ->middleware('rate_limit:analytics');

            Route::get('/insights/customers', [AnalyticsController::class, 'getCustomerInsights'])
                ->middleware('rate_limit:analytics');
        });

        // Analytics Health routes (Phase 3) - Admin only access
        Route::prefix('analytics-health')->middleware('rbac.event_management')->group(function () {
            Route::get('/dashboard', [Modules\EventManagement\Http\Controllers\Admin\AnalyticsHealthController::class, 'dashboard'])
                ->middleware('rate_limit:analytics');

            Route::post('/run-health-checks', [Modules\EventManagement\Http\Controllers\Admin\AnalyticsHealthController::class, 'runHealthChecks'])
                ->middleware('rate_limit:analytics');

            Route::get('/health-check-details', [Modules\EventManagement\Http\Controllers\Admin\AnalyticsHealthController::class, 'getHealthCheckDetails'])
                ->middleware('rate_limit:analytics');

            Route::get('/kpi-definitions', [Modules\EventManagement\Http\Controllers\Admin\AnalyticsHealthController::class, 'getKPIDefinitions'])
                ->middleware('rate_limit:analytics');

            Route::post('/kpi-definitions', [Modules\EventManagement\Http\Controllers\Admin\AnalyticsHealthController::class, 'saveKPIDefinition'])
                ->middleware('rate_limit:analytics');

            Route::get('/reconciliation-report', [Modules\EventManagement\Http\Controllers\Admin\AnalyticsHealthController::class, 'getReconciliationReport'])
                ->middleware('rate_limit:analytics');

            Route::post('/invalidate-cache', [Modules\EventManagement\Http\Controllers\Admin\AnalyticsHealthController::class, 'invalidateCache'])
                ->middleware('rate_limit:analytics');

            Route::post('/backfill-historical-data', [Modules\EventManagement\Http\Controllers\Admin\AnalyticsHealthController::class, 'backfillHistoricalData'])
                ->middleware('rate_limit:analytics');

            Route::get('/data-quality-alerts', [Modules\EventManagement\Http\Controllers\Admin\AnalyticsHealthController::class, 'getDataQualityAlerts'])
                ->middleware('rate_limit:analytics');
        });

        // Artist Management routes (Phase 3) - Admin only access
        Route::prefix('artist-management')->middleware('rbac.event_management')->group(function () {
            Route::get('/dashboard', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'dashboard'])
                ->middleware('rate_limit:admin_api');

            Route::get('/search', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'searchArtists'])
                ->middleware('rate_limit:admin_api');

            Route::get('/canonical/{artistId}', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'getCanonicalArtist'])
                ->middleware('rate_limit:admin_api');

            Route::post('/canonical', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'createCanonicalArtist'])
                ->middleware('rate_limit:admin_api');

            Route::post('/aliases', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'createAlias'])
                ->middleware('rate_limit:admin_api');

            Route::get('/merge-candidates/{artistId}', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'getMergeCandidates'])
                ->middleware('rate_limit:admin_api');

            Route::post('/merge', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'mergeArtists'])
                ->middleware('rate_limit:admin_api');

            Route::get('/merge-history', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'getMergeHistory'])
                ->middleware('rate_limit:admin_api');

            Route::post('/bulk-import-aliases', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'bulkImportAliases'])
                ->middleware('rate_limit:admin_api');

            Route::get('/relationship-visualization/{artistId}', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'getRelationshipVisualization'])
                ->middleware('rate_limit:admin_api');
        });

        // Fraud Detection routes (Phase 2) - Admin only access
        Route::prefix('fraud-detection')->middleware('rbac.event_management')->group(function () {
            Route::get('/statistics', [FraudDetectionController::class, 'getStatistics'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::get('/alerts', [FraudDetectionController::class, 'getAlerts'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::put('/alerts/{alertId}/status', [FraudDetectionController::class, 'updateAlertStatus'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::post('/analyze/customer-velocity', [FraudDetectionController::class, 'analyzeCustomerVelocity'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::post('/analyze/ip-velocity', [FraudDetectionController::class, 'analyzeIpVelocity'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::get('/velocity-logs', [FraudDetectionController::class, 'getVelocityLogs'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::post('/detect-patterns', [FraudDetectionController::class, 'detectPatterns'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::post('/process-review-queue', [FraudDetectionController::class, 'processReviewQueue'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::post('/cleanup-expired-data', [FraudDetectionController::class, 'cleanupExpiredData'])
                ->middleware('rate_limit:fraud_monitoring');
        });

        // Anti-Abuse routes (Phase 2) - Admin only access
        Route::prefix('anti-abuse')->middleware('rbac.event_management')->group(function () {
            Route::get('/ip-restrictions', [AntiAbuseController::class, 'getIpRestrictions'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::post('/ip-restrictions/block', [AntiAbuseController::class, 'blockIpAddress'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::post('/ip-restrictions/allowlist', [AntiAbuseController::class, 'allowlistIpAddress'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::post('/ip-restrictions/flag', [AntiAbuseController::class, 'flagIpAddress'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::delete('/ip-restrictions/{restrictionId}', [AntiAbuseController::class, 'removeIpRestriction'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::get('/fraud-rules', [AntiAbuseController::class, 'getFraudRules'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::post('/fraud-rules', [AntiAbuseController::class, 'createFraudRule'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::put('/fraud-rules/{ruleId}', [AntiAbuseController::class, 'updateFraudRule'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::delete('/fraud-rules/{ruleId}', [AntiAbuseController::class, 'deleteFraudRule'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::post('/fraud-rules/{ruleId}/test', [AntiAbuseController::class, 'testFraudRule'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::post('/check-rate-limit', [AntiAbuseController::class, 'checkRateLimit'])
                ->middleware('rate_limit:fraud_monitoring');
        });

        // Manual Review routes (Phase 2) - Admin only access
        Route::prefix('manual-review')->middleware('rbac.event_management')->group(function () {
            Route::get('/queue', [ManualReviewController::class, 'getReviewQueue'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::get('/queue/{reviewId}', [ManualReviewController::class, 'getReviewDetails'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::post('/queue/{reviewId}/approve', [ManualReviewController::class, 'approveReview'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::post('/queue/{reviewId}/reject', [ManualReviewController::class, 'rejectReview'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::post('/queue/{reviewId}/escalate', [ManualReviewController::class, 'escalateReview'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::post('/queue/bulk-process', [ManualReviewController::class, 'bulkProcessReviews'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::get('/statistics', [ManualReviewController::class, 'getQueueStatistics'])
                ->middleware('rate_limit:fraud_monitoring');

            Route::post('/auto-process', [ManualReviewController::class, 'autoProcessQueue'])
                ->middleware('rate_limit:fraud_monitoring');
        });

        // Identity Verification routes (Phase 2) - Customer access with rate limiting
        Route::prefix('identity-verification')->group(function () {
            Route::get('/status/{customerId}', [IdentityVerificationController::class, 'getVerificationStatus'])
                ->middleware('rate_limit:identity_verification');

            Route::post('/check-requirement', [IdentityVerificationController::class, 'checkVerificationRequirement'])
                ->middleware('rate_limit:identity_verification');

            Route::post('/submit', [IdentityVerificationController::class, 'submitVerification'])
                ->middleware('rate_limit:identity_verification');

            Route::post('/resubmit/{customerId}', [IdentityVerificationController::class, 'resubmitVerification'])
                ->middleware('rate_limit:identity_verification');

            Route::get('/history/{customerId}', [IdentityVerificationController::class, 'getVerificationHistory'])
                ->middleware('rate_limit:identity_verification');

            Route::post('/upload-documents', [IdentityVerificationController::class, 'uploadAdditionalDocuments'])
                ->middleware('rate_limit:identity_verification');

            Route::post('/cancel/{customerId}', [IdentityVerificationController::class, 'cancelVerification'])
                ->middleware('rate_limit:identity_verification');

            Route::get('/requirements', [IdentityVerificationController::class, 'getVerificationRequirements'])
                ->middleware('rate_limit:identity_verification');

            Route::get('/upload-requirements', [IdentityVerificationController::class, 'getUploadRequirements'])
                ->middleware('rate_limit:identity_verification');

            Route::post('/validate-document', [IdentityVerificationController::class, 'validateDocument'])
                ->middleware('rate_limit:identity_verification');

            Route::get('/stats/{customerId}', [IdentityVerificationController::class, 'getCustomerVerificationStats'])
                ->middleware(['rate_limit:identity_verification', 'rbac.event_management']);
        });

        // Event Operations Routes (Staff/Admin Only)
        Route::prefix('event-operations')->middleware('rbac.event_management')->group(function () {
            Route::get('/dashboard/{eventId}', [EventOperationsController::class, 'getLiveDashboard'])
                ->middleware('rate_limit:operations_dashboard');

            Route::get('/real-time-status/{eventId}', [EventOperationsController::class, 'getRealTimeStatus'])
                ->middleware('rate_limit:operations_dashboard');

            Route::get('/check-in-monitoring/{eventId}', [EventOperationsController::class, 'getCheckInMonitoring'])
                ->middleware('rate_limit:operations_dashboard');

            Route::get('/queue-management/{eventId}', [EventOperationsController::class, 'getQueueManagement'])
                ->middleware('rate_limit:operations_dashboard');

            Route::get('/capacity-monitoring/{eventId}', [EventOperationsController::class, 'getCapacityMonitoring'])
                ->middleware('rate_limit:operations_dashboard');

            Route::get('/zone-status/{eventId}', [EventOperationsController::class, 'getZoneStatus'])
                ->middleware('rate_limit:operations_dashboard');

            Route::get('/staff-overview/{eventId}', [EventOperationsController::class, 'getStaffOverview'])
                ->middleware('rate_limit:operations_dashboard');

            Route::get('/incident-summary/{eventId}', [EventOperationsController::class, 'getIncidentSummary'])
                ->middleware('rate_limit:operations_dashboard');

            Route::post('/update-event-status/{eventId}', [EventOperationsController::class, 'updateEventStatus'])
                ->middleware('rate_limit:operations_management');

            Route::post('/emergency-broadcast/{eventId}', [EventOperationsController::class, 'emergencyBroadcast'])
                ->middleware('rate_limit:operations_management');

            Route::get('/handover-report/{eventId}', [EventOperationsController::class, 'generateHandoverReport'])
                ->middleware('rate_limit:operations_dashboard');
        });

        // Staff Management Routes (Admin/Manager Only)
        Route::prefix('staff')->middleware('rbac.event_management')->group(function () {
            Route::post('/events/{eventId}/assign', [StaffController::class, 'assignStaff'])
                ->middleware('rate_limit:staff_management');

            Route::put('/assignments/{assignmentId}', [StaffController::class, 'updateAssignment'])
                ->middleware('rate_limit:staff_management');

            Route::post('/assignments/{assignmentId}/check-in-out', [StaffController::class, 'checkInOut'])
                ->middleware('rate_limit:staff_management');

            Route::put('/assignments/{assignmentId}/status', [StaffController::class, 'updateStatus'])
                ->middleware('rate_limit:staff_management');

            Route::get('/events/{eventId}/assignments', [StaffController::class, 'getEventStaffAssignments'])
                ->middleware('rate_limit:staff_management');

            Route::get('/{staffUserId}/history', [StaffController::class, 'getStaffHistory'])
                ->middleware('rate_limit:staff_management');

            Route::get('/{staffUserId}/performance', [StaffController::class, 'getStaffPerformance'])
                ->middleware('rate_limit:staff_management');

            Route::get('/{staffUserId}/events/{eventId}/zone-access', [StaffController::class, 'getStaffZoneAccess'])
                ->middleware('rate_limit:staff_management');

            Route::get('/events/{eventId}/scheduling-report', [StaffController::class, 'generateSchedulingReport'])
                ->middleware('rate_limit:staff_management');

            Route::delete('/assignments/{assignmentId}', [StaffController::class, 'removeAssignment'])
                ->middleware('rate_limit:staff_management');

            Route::get('/roles', [StaffController::class, 'getAvailableRoles'])
                ->middleware('rate_limit:staff_management');

            Route::get('/assignments/{assignmentId}', [StaffController::class, 'getAssignment'])
                ->middleware('rate_limit:staff_management');

            Route::get('/performance-report', [StaffController::class, 'getPerformanceReport'])
                ->middleware('rate_limit:staff_management');
        });

        // Incident Management Routes (Staff/Admin Only)
        Route::prefix('incidents')->middleware('rbac.event_management')->group(function () {
            Route::get('/events/{eventId}', [IncidentController::class, 'getEventIncidents'])
                ->middleware('rate_limit:incident_management');

            Route::get('/{incidentId}', [IncidentController::class, 'getIncident'])
                ->middleware('rate_limit:incident_management');

            Route::post('/', [IncidentController::class, 'createIncident'])
                ->middleware('rate_limit:incident_management');

            Route::put('/{incidentId}', [IncidentController::class, 'updateIncident'])
                ->middleware('rate_limit:incident_management');

            Route::post('/{incidentId}/acknowledge', [IncidentController::class, 'acknowledgeIncident'])
                ->middleware('rate_limit:incident_management');

            Route::post('/{incidentId}/assign', [IncidentController::class, 'assignIncident'])
                ->middleware('rate_limit:incident_management');

            Route::post('/{incidentId}/in-progress', [IncidentController::class, 'markInProgress'])
                ->middleware('rate_limit:incident_management');

            Route::post('/{incidentId}/resolve', [IncidentController::class, 'resolveIncident'])
                ->middleware('rate_limit:incident_management');

            Route::post('/{incidentId}/escalate', [IncidentController::class, 'escalateIncident'])
                ->middleware('rate_limit:incident_management');

            Route::post('/{incidentId}/close', [IncidentController::class, 'closeIncident'])
                ->middleware('rate_limit:incident_management');

            Route::get('/events/{eventId}/statistics', [IncidentController::class, 'getIncidentStatistics'])
                ->middleware('rate_limit:incident_management');
        });

        // Operational Check-in Routes (Staff Only)
        Route::prefix('operational-checkin')->group(function () {
            Route::post('/ticket', [OperationalCheckInController::class, 'checkInTicket'])
                ->middleware('rbac.event_management:operations.checkin');

            Route::post('/bulk', [OperationalCheckInController::class, 'bulkCheckIn'])
                ->middleware('rbac.event_management:operations.checkin');

            Route::post('/booking', [OperationalCheckInController::class, 'checkInByBooking'])
                ->middleware('rbac.event_management:operations.checkin');

            Route::post('/emergency-override', [OperationalCheckInController::class, 'emergencyOverride'])
                ->middleware('rbac.event_management:operations.override');

            Route::get('/statistics/{eventId}', [OperationalCheckInController::class, 'getCheckInStatistics'])
                ->middleware('rbac.event_management:operations.view');

            Route::get('/queue-management/{eventId}', [OperationalCheckInController::class, 'getQueueManagement'])
                ->middleware('rbac.event_management:operations.view');

            Route::get('/zone-capacity/{eventId}', [OperationalCheckInController::class, 'monitorZoneCapacity'])
                ->middleware('rbac.event_management:operations.view');

            Route::get('/dashboard/{eventId}', [OperationalCheckInController::class, 'getOperationalDashboard'])
                ->middleware('rbac.event_management:operations.view');
        });
    });

// Public API Routes (no authentication required, but rate limited)
Route::prefix('public/event-management')
    ->middleware(['request.validation', 'entitlement:event_management', 'rate_limit:public_api'])
    ->group(function () {

        // Public event routes
        Route::prefix('events')->group(function () {
            Route::get('/', [EventController::class, 'publicIndex']);
            Route::get('/{id}', [EventController::class, 'publicShow']);
            Route::get('/{id}/availability', [EventController::class, 'checkAvailability']);
        });

        // Public booking routes (for customers)
        Route::prefix('bookings')->group(function () {
            Route::post('/', [BookingController::class, 'store'])
                ->middleware('rate_limit:booking_creation');

            Route::get('/reference/{reference}', [BookingController::class, 'showByReference']);

            Route::post('/check-availability', [BookingController::class, 'checkAvailability']);
        });

        // Public purchase routes (Phase 4)
        // Requirements: 3.4, 4.4, 8.1, 8.2, 8.3
        Route::prefix('purchase')->group(function () {
            Route::post('/tickets', [PurchaseController::class, 'purchaseTickets'])
                ->middleware('rate_limit:booking_creation');
        });

        // Public bidding routes (Phase 4)
        // Requirements: 3.5, 4.5, 8.1, 8.2, 8.3
        Route::prefix('bidding')->group(function () {
            Route::post('/submit', [BiddingController::class, 'submitBid'])
                ->middleware('rate_limit:booking_creation');
        });

        // Public ticket routes (limited access)
        Route::prefix('tickets')->group(function () {
            Route::get('/{ticketCode}/qr-code', [TicketController::class, 'qrCode'])
                ->middleware('rate_limit:ticket_validation');
        });

        // Public queue management routes (for high-traffic event launches)
        Route::prefix('queue')->group(function () {
            Route::post('/join', [EventQueueController::class, 'join']);
            Route::get('/status', [EventQueueController::class, 'status']);
            Route::post('/heartbeat', [EventQueueController::class, 'heartbeat']);
            Route::post('/leave', [EventQueueController::class, 'leave']);
            Route::get('/check-access', [EventQueueController::class, 'checkAccess']);
        });

        // Public waiting room routes (mini games, rewards, flash sales)
        Route::prefix('waiting-room')->group(function () {
            Route::get('/games', [WaitingRoomController::class, 'getGames']);
            Route::post('/start-session', [WaitingRoomController::class, 'startSession']);
            Route::post('/play', [WaitingRoomController::class, 'play']);
            Route::get('/flash-sales', [WaitingRoomController::class, 'getFlashSales']);
        });

        // Customer hub routes for logged-in users
        Route::prefix('customer/waiting-room')->middleware('auth:sanctum')->group(function () {
            Route::get('/rewards', [WaitingRoomController::class, 'getMyRewards']);
            Route::post('/rewards/redeem', [WaitingRoomController::class, 'redeemReward']);
        });
    });

// Payment callback routes (special rate limiting)
Route::prefix('webhooks/event-management')
    ->middleware(['request.validation', 'entitlement:event_management', 'rate_limit:payment_callback'])
    ->group(function () {

        Route::post('/payment-callback', [BookingController::class, 'paymentCallback']);
    });

// Customer Hub Routes
Route::prefix('customer')->middleware(['auth:sanctum', 'request.validation', 'entitlement:event_management'])->group(function () {
    Route::get('/dashboard', [Modules\EventManagement\Http\Controllers\CustomerHubController::class, 'getDashboard']);
    Route::post('/dashboard/recommendations/{recommendationId}/track', [Modules\EventManagement\Http\Controllers\CustomerHubController::class, 'trackRecommendation']);

    // Feedback routes
    Route::prefix('feedback')->group(function () {
        Route::get('/types',              [Modules\EventManagement\Http\Controllers\FeedbackController::class, 'getFeedbackTypes']);
        Route::get('/rating-categories',  [Modules\EventManagement\Http\Controllers\FeedbackController::class, 'getRatingCategories']);
        Route::get('/my-feedback',        [Modules\EventManagement\Http\Controllers\FeedbackController::class, 'getMyFeedback']);
        Route::get('/stats/{eventId}',    [Modules\EventManagement\Http\Controllers\FeedbackController::class, 'getFeedbackStats']);
        Route::get('/',                   [Modules\EventManagement\Http\Controllers\FeedbackController::class, 'getFeedback']);
        Route::post('/',                  [Modules\EventManagement\Http\Controllers\FeedbackController::class, 'submitFeedback']);
        Route::post('/{feedbackId}/vote', [Modules\EventManagement\Http\Controllers\FeedbackController::class, 'voteFeedback']);
    });
});

// Ticket Download Route
Route::prefix('bookings')->middleware(['request.validation', 'entitlement:event_management'])->group(function () {
    Route::get('/{bookingId}/tickets/download', [Modules\EventManagement\Http\Controllers\TicketController::class, 'download']);
});