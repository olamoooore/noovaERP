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
Route::prefix('api/v1/event-management')
    ->middleware(['api', 'request.validation', 'feature.event_management', 'rbac.event_management', 'rate_limit:admin_api'])
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
                ->middleware('rbac:staff,admin');

            // Bid management routes (staff/admin only)
            Route::post('/{bidId}/{action}', [BiddingController::class, 'handleBidAction'])
                ->middleware('rbac:staff,admin')
                ->where('action', 'accept|reject');

            Route::get('/{bidId}', [BiddingController::class, 'getBidDetails'])
                ->middleware('rbac:staff,admin');

            Route::post('/bulk-process', [BiddingController::class, 'bulkProcessBids'])
                ->middleware('rbac:staff,admin');
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
        Route::prefix('analytics')->middleware('rbac:admin')->group(function () {
            Route::get('/dashboard', [AnalyticsController::class, 'getDashboardSummary'])
                ->middleware('throttle:analytics');

            Route::get('/events/{eventId}/kpis', [AnalyticsController::class, 'getEventKPIs'])
                ->middleware('throttle:analytics');

            Route::get('/events/{eventId}/ticket-performance', [AnalyticsController::class, 'getTicketTypePerformance'])
                ->middleware('throttle:analytics');

            Route::get('/events/{eventId}/sales-velocity', [AnalyticsController::class, 'getSalesVelocity'])
                ->middleware('throttle:analytics');

            Route::get('/events/{eventId}/repeat-attendees', [AnalyticsController::class, 'getRepeatAttendees'])
                ->middleware('throttle:analytics');

            Route::post('/reports/generate', [AnalyticsController::class, 'generateReport'])
                ->middleware('throttle:analytics');

            Route::post('/reports/export', [AnalyticsController::class, 'exportReport'])
                ->middleware('throttle:analytics');

            Route::get('/trends/sales', [AnalyticsController::class, 'getSalesTrends'])
                ->middleware('throttle:analytics');

            Route::get('/insights/customers', [AnalyticsController::class, 'getCustomerInsights'])
                ->middleware('throttle:analytics');
        });

        // Analytics Health routes (Phase 3) - Admin only access
        Route::prefix('analytics-health')->middleware('rbac:admin')->group(function () {
            Route::get('/dashboard', [Modules\EventManagement\Http\Controllers\Admin\AnalyticsHealthController::class, 'dashboard'])
                ->middleware('throttle:analytics');

            Route::post('/run-health-checks', [Modules\EventManagement\Http\Controllers\Admin\AnalyticsHealthController::class, 'runHealthChecks'])
                ->middleware('throttle:analytics');

            Route::get('/health-check-details', [Modules\EventManagement\Http\Controllers\Admin\AnalyticsHealthController::class, 'getHealthCheckDetails'])
                ->middleware('throttle:analytics');

            Route::get('/kpi-definitions', [Modules\EventManagement\Http\Controllers\Admin\AnalyticsHealthController::class, 'getKPIDefinitions'])
                ->middleware('throttle:analytics');

            Route::post('/kpi-definitions', [Modules\EventManagement\Http\Controllers\Admin\AnalyticsHealthController::class, 'saveKPIDefinition'])
                ->middleware('throttle:analytics');

            Route::get('/reconciliation-report', [Modules\EventManagement\Http\Controllers\Admin\AnalyticsHealthController::class, 'getReconciliationReport'])
                ->middleware('throttle:analytics');

            Route::post('/invalidate-cache', [Modules\EventManagement\Http\Controllers\Admin\AnalyticsHealthController::class, 'invalidateCache'])
                ->middleware('throttle:analytics');

            Route::post('/backfill-historical-data', [Modules\EventManagement\Http\Controllers\Admin\AnalyticsHealthController::class, 'backfillHistoricalData'])
                ->middleware('throttle:analytics');

            Route::get('/data-quality-alerts', [Modules\EventManagement\Http\Controllers\Admin\AnalyticsHealthController::class, 'getDataQualityAlerts'])
                ->middleware('throttle:analytics');
        });

        // Artist Management routes (Phase 3) - Admin only access
        Route::prefix('artist-management')->middleware('rbac:admin')->group(function () {
            Route::get('/dashboard', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'dashboard'])
                ->middleware('throttle:admin_api');

            Route::get('/search', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'searchArtists'])
                ->middleware('throttle:admin_api');

            Route::get('/canonical/{artistId}', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'getCanonicalArtist'])
                ->middleware('throttle:admin_api');

            Route::post('/canonical', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'createCanonicalArtist'])
                ->middleware('throttle:admin_api');

            Route::post('/aliases', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'createAlias'])
                ->middleware('throttle:admin_api');

            Route::get('/merge-candidates/{artistId}', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'getMergeCandidates'])
                ->middleware('throttle:admin_api');

            Route::post('/merge', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'mergeArtists'])
                ->middleware('throttle:admin_api');

            Route::get('/merge-history', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'getMergeHistory'])
                ->middleware('throttle:admin_api');

            Route::post('/bulk-import-aliases', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'bulkImportAliases'])
                ->middleware('throttle:admin_api');

            Route::get('/relationship-visualization/{artistId}', [Modules\EventManagement\Http\Controllers\Admin\ArtistManagementController::class, 'getRelationshipVisualization'])
                ->middleware('throttle:admin_api');
        });

        // Fraud Detection routes (Phase 2) - Admin only access
        Route::prefix('fraud-detection')->middleware('rbac:admin')->group(function () {
            Route::get('/statistics', [FraudDetectionController::class, 'getStatistics'])
                ->middleware('throttle:fraud_monitoring');

            Route::get('/alerts', [FraudDetectionController::class, 'getAlerts'])
                ->middleware('throttle:fraud_monitoring');

            Route::put('/alerts/{alertId}/status', [FraudDetectionController::class, 'updateAlertStatus'])
                ->middleware('throttle:fraud_monitoring');

            Route::post('/analyze/customer-velocity', [FraudDetectionController::class, 'analyzeCustomerVelocity'])
                ->middleware('throttle:fraud_monitoring');

            Route::post('/analyze/ip-velocity', [FraudDetectionController::class, 'analyzeIpVelocity'])
                ->middleware('throttle:fraud_monitoring');

            Route::get('/velocity-logs', [FraudDetectionController::class, 'getVelocityLogs'])
                ->middleware('throttle:fraud_monitoring');

            Route::post('/detect-patterns', [FraudDetectionController::class, 'detectPatterns'])
                ->middleware('throttle:fraud_monitoring');

            Route::post('/process-review-queue', [FraudDetectionController::class, 'processReviewQueue'])
                ->middleware('throttle:fraud_monitoring');

            Route::post('/cleanup-expired-data', [FraudDetectionController::class, 'cleanupExpiredData'])
                ->middleware('throttle:fraud_monitoring');
        });

        // Anti-Abuse routes (Phase 2) - Admin only access
        Route::prefix('anti-abuse')->middleware('rbac:admin')->group(function () {
            Route::get('/ip-restrictions', [AntiAbuseController::class, 'getIpRestrictions'])
                ->middleware('throttle:fraud_monitoring');

            Route::post('/ip-restrictions/block', [AntiAbuseController::class, 'blockIpAddress'])
                ->middleware('throttle:fraud_monitoring');

            Route::post('/ip-restrictions/allowlist', [AntiAbuseController::class, 'allowlistIpAddress'])
                ->middleware('throttle:fraud_monitoring');

            Route::post('/ip-restrictions/flag', [AntiAbuseController::class, 'flagIpAddress'])
                ->middleware('throttle:fraud_monitoring');

            Route::delete('/ip-restrictions/{restrictionId}', [AntiAbuseController::class, 'removeIpRestriction'])
                ->middleware('throttle:fraud_monitoring');

            Route::get('/fraud-rules', [AntiAbuseController::class, 'getFraudRules'])
                ->middleware('throttle:fraud_monitoring');

            Route::post('/fraud-rules', [AntiAbuseController::class, 'createFraudRule'])
                ->middleware('throttle:fraud_monitoring');

            Route::put('/fraud-rules/{ruleId}', [AntiAbuseController::class, 'updateFraudRule'])
                ->middleware('throttle:fraud_monitoring');

            Route::delete('/fraud-rules/{ruleId}', [AntiAbuseController::class, 'deleteFraudRule'])
                ->middleware('throttle:fraud_monitoring');

            Route::post('/fraud-rules/{ruleId}/test', [AntiAbuseController::class, 'testFraudRule'])
                ->middleware('throttle:fraud_monitoring');

            Route::post('/check-rate-limit', [AntiAbuseController::class, 'checkRateLimit'])
                ->middleware('throttle:fraud_monitoring');
        });

        // Manual Review routes (Phase 2) - Admin only access
        Route::prefix('manual-review')->middleware('rbac:admin')->group(function () {
            Route::get('/queue', [ManualReviewController::class, 'getReviewQueue'])
                ->middleware('throttle:fraud_monitoring');

            Route::get('/queue/{reviewId}', [ManualReviewController::class, 'getReviewDetails'])
                ->middleware('throttle:fraud_monitoring');

            Route::post('/queue/{reviewId}/approve', [ManualReviewController::class, 'approveReview'])
                ->middleware('throttle:fraud_monitoring');

            Route::post('/queue/{reviewId}/reject', [ManualReviewController::class, 'rejectReview'])
                ->middleware('throttle:fraud_monitoring');

            Route::post('/queue/{reviewId}/escalate', [ManualReviewController::class, 'escalateReview'])
                ->middleware('throttle:fraud_monitoring');

            Route::post('/queue/bulk-process', [ManualReviewController::class, 'bulkProcessReviews'])
                ->middleware('throttle:fraud_monitoring');

            Route::get('/statistics', [ManualReviewController::class, 'getQueueStatistics'])
                ->middleware('throttle:fraud_monitoring');

            Route::post('/auto-process', [ManualReviewController::class, 'autoProcessQueue'])
                ->middleware('throttle:fraud_monitoring');
        });

        // Identity Verification routes (Phase 2) - Customer access with rate limiting
        Route::prefix('identity-verification')->group(function () {
            Route::get('/status/{customerId}', [IdentityVerificationController::class, 'getVerificationStatus'])
                ->middleware('throttle:identity_verification');

            Route::post('/check-requirement', [IdentityVerificationController::class, 'checkVerificationRequirement'])
                ->middleware('throttle:identity_verification');

            Route::post('/submit', [IdentityVerificationController::class, 'submitVerification'])
                ->middleware('throttle:identity_verification');

            Route::post('/resubmit/{customerId}', [IdentityVerificationController::class, 'resubmitVerification'])
                ->middleware('throttle:identity_verification');

            Route::get('/history/{customerId}', [IdentityVerificationController::class, 'getVerificationHistory'])
                ->middleware('throttle:identity_verification');

            Route::post('/upload-documents', [IdentityVerificationController::class, 'uploadAdditionalDocuments'])
                ->middleware('throttle:identity_verification');

            Route::post('/cancel/{customerId}', [IdentityVerificationController::class, 'cancelVerification'])
                ->middleware('throttle:identity_verification');

            Route::get('/requirements', [IdentityVerificationController::class, 'getVerificationRequirements'])
                ->middleware('throttle:identity_verification');

            Route::get('/upload-requirements', [IdentityVerificationController::class, 'getUploadRequirements'])
                ->middleware('throttle:identity_verification');

            Route::post('/validate-document', [IdentityVerificationController::class, 'validateDocument'])
                ->middleware('throttle:identity_verification');

            Route::get('/stats/{customerId}', [IdentityVerificationController::class, 'getCustomerVerificationStats'])
                ->middleware(['throttle:identity_verification', 'rbac:admin']);
        });

        // Event Operations Routes (Staff/Admin Only)
        Route::prefix('event-operations')->middleware('rbac:staff,admin')->group(function () {
            Route::get('/dashboard/{eventId}', [EventOperationsController::class, 'getLiveDashboard'])
                ->middleware('throttle:operations_dashboard');

            Route::get('/real-time-status/{eventId}', [EventOperationsController::class, 'getRealTimeStatus'])
                ->middleware('throttle:operations_dashboard');

            Route::get('/check-in-monitoring/{eventId}', [EventOperationsController::class, 'getCheckInMonitoring'])
                ->middleware('throttle:operations_dashboard');

            Route::get('/queue-management/{eventId}', [EventOperationsController::class, 'getQueueManagement'])
                ->middleware('throttle:operations_dashboard');

            Route::get('/capacity-monitoring/{eventId}', [EventOperationsController::class, 'getCapacityMonitoring'])
                ->middleware('throttle:operations_dashboard');

            Route::get('/zone-status/{eventId}', [EventOperationsController::class, 'getZoneStatus'])
                ->middleware('throttle:operations_dashboard');

            Route::get('/staff-overview/{eventId}', [EventOperationsController::class, 'getStaffOverview'])
                ->middleware('throttle:operations_dashboard');

            Route::get('/incident-summary/{eventId}', [EventOperationsController::class, 'getIncidentSummary'])
                ->middleware('throttle:operations_dashboard');

            Route::post('/update-event-status/{eventId}', [EventOperationsController::class, 'updateEventStatus'])
                ->middleware('throttle:operations_management');

            Route::post('/emergency-broadcast/{eventId}', [EventOperationsController::class, 'emergencyBroadcast'])
                ->middleware('throttle:operations_management');

            Route::get('/handover-report/{eventId}', [EventOperationsController::class, 'generateHandoverReport'])
                ->middleware('throttle:operations_dashboard');
        });

        // Staff Management Routes (Admin/Manager Only)
        Route::prefix('staff')->middleware('rbac:admin,manager')->group(function () {
            Route::post('/events/{eventId}/assign', [StaffController::class, 'assignStaff'])
                ->middleware('throttle:staff_management');

            Route::put('/assignments/{assignmentId}', [StaffController::class, 'updateAssignment'])
                ->middleware('throttle:staff_management');

            Route::post('/assignments/{assignmentId}/check-in-out', [StaffController::class, 'checkInOut'])
                ->middleware('throttle:staff_management');

            Route::put('/assignments/{assignmentId}/status', [StaffController::class, 'updateStatus'])
                ->middleware('throttle:staff_management');

            Route::get('/events/{eventId}/assignments', [StaffController::class, 'getEventStaffAssignments'])
                ->middleware('throttle:staff_management');

            Route::get('/{staffUserId}/history', [StaffController::class, 'getStaffHistory'])
                ->middleware('throttle:staff_management');

            Route::get('/{staffUserId}/performance', [StaffController::class, 'getStaffPerformance'])
                ->middleware('throttle:staff_management');

            Route::get('/{staffUserId}/events/{eventId}/zone-access', [StaffController::class, 'getStaffZoneAccess'])
                ->middleware('throttle:staff_management');

            Route::get('/events/{eventId}/scheduling-report', [StaffController::class, 'generateSchedulingReport'])
                ->middleware('throttle:staff_management');

            Route::delete('/assignments/{assignmentId}', [StaffController::class, 'removeAssignment'])
                ->middleware('throttle:staff_management');

            Route::get('/roles', [StaffController::class, 'getAvailableRoles'])
                ->middleware('throttle:staff_management');

            Route::get('/assignments/{assignmentId}', [StaffController::class, 'getAssignment'])
                ->middleware('throttle:staff_management');
        });

        // Incident Management Routes (Staff/Admin Only)
        Route::prefix('incidents')->middleware('rbac:staff,admin')->group(function () {
            Route::get('/events/{eventId}', [IncidentController::class, 'getEventIncidents'])
                ->middleware('throttle:incident_management');

            Route::get('/{incidentId}', [IncidentController::class, 'getIncident'])
                ->middleware('throttle:incident_management');

            Route::post('/', [IncidentController::class, 'createIncident'])
                ->middleware('throttle:incident_management');

            Route::put('/{incidentId}', [IncidentController::class, 'updateIncident'])
                ->middleware('throttle:incident_management');

            Route::post('/{incidentId}/acknowledge', [IncidentController::class, 'acknowledgeIncident'])
                ->middleware('throttle:incident_management');

            Route::post('/{incidentId}/assign', [IncidentController::class, 'assignIncident'])
                ->middleware('throttle:incident_management');

            Route::post('/{incidentId}/in-progress', [IncidentController::class, 'markInProgress'])
                ->middleware('throttle:incident_management');

            Route::post('/{incidentId}/resolve', [IncidentController::class, 'resolveIncident'])
                ->middleware('throttle:incident_management');

            Route::post('/{incidentId}/escalate', [IncidentController::class, 'escalateIncident'])
                ->middleware('throttle:incident_management');

            Route::post('/{incidentId}/close', [IncidentController::class, 'closeIncident'])
                ->middleware('throttle:incident_management');

            Route::get('/events/{eventId}/statistics', [IncidentController::class, 'getIncidentStatistics'])
                ->middleware('throttle:incident_management');
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
Route::prefix('api/v1/public/event-management')
    ->middleware(['api', 'request.validation', 'feature.event_management', 'rate_limit:public_api'])
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
    });

// Payment callback routes (special rate limiting)
Route::prefix('api/v1/webhooks/event-management')
    ->middleware(['api', 'request.validation', 'feature.event_management', 'rate_limit:payment_callback'])
    ->group(function () {

        Route::post('/payment-callback', [BookingController::class, 'paymentCallback']);
    });

// Customer Hub Routes
Route::prefix('customer')->middleware(['api', 'request.validation', 'feature.event_management'])->group(function () {
    Route::get('/dashboard', [Modules\EventManagement\Http\Controllers\CustomerHubController::class, 'getDashboard']);
    Route::post('/dashboard/recommendations/{recommendationId}/track', [Modules\EventManagement\Http\Controllers\CustomerHubController::class, 'trackRecommendation']);
});

// Ticket Download Route
Route::prefix('bookings')->middleware(['api', 'request.validation', 'feature.event_management'])->group(function () {
    Route::get('/{bookingId}/tickets/download', [Modules\EventManagement\Http\Controllers\TicketController::class, 'download']);
});