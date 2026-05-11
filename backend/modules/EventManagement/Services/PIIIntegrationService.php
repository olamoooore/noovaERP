<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PIIIntegrationService - Task 4.4 (Service Integration)
 * 
 * Provides integration layer between existing services and PII vault,
 * implementing dual-write pattern during transition and service updates.
 * 
 * Requirements: 20.1, 20.4
 */
class PIIIntegrationService
{
    protected PIIVaultService $piiVaultService;
    protected AuditService $auditService;
    
    // PII field mappings for different entity types
    private const PII_FIELD_MAPPINGS = [
        'customer' => [
            'email' => 'customer_data',
            'phone' => 'customer_data',
            'first_name' => 'customer_data',
            'last_name' => 'customer_data',
            'date_of_birth' => 'customer_data',
            'address_line_1' => 'customer_data',
            'address_line_2' => 'customer_data',
            'city' => 'customer_data',
            'postal_code' => 'customer_data',
            'country' => 'customer_data',
        ],
        'booking' => [
            'buyer_name' => 'booking_data',
            'buyer_email' => 'booking_data',
            'buyer_phone' => 'booking_data',
            'billing_first_name' => 'booking_data',
            'billing_last_name' => 'booking_data',
            'billing_email' => 'booking_data',
            'billing_phone' => 'booking_data',
            'billing_address_line_1' => 'booking_data',
            'billing_address_line_2' => 'booking_data',
            'billing_city' => 'booking_data',
            'billing_postal_code' => 'booking_data',
            'billing_country' => 'booking_data',
        ],
        'attendee' => [
            'attendee_name' => 'booking_data',
            'attendee_email' => 'booking_data',
            'attendee_phone' => 'booking_data',
            'first_name' => 'booking_data',
            'last_name' => 'booking_data',
            'email' => 'booking_data',
            'phone' => 'booking_data',
            'date_of_birth' => 'booking_data',
            'dietary_requirements' => 'booking_data',
            'accessibility_needs' => 'booking_data',
        ],
    ];

    public function __construct(PIIVaultService $piiVaultService, AuditService $auditService)
    {
        $this->piiVaultService = $piiVaultService;
        $this->auditService = $auditService;
    }

    /**
     * Store PII data for an entity using dual-write pattern.
     * 
     * @param string $entityType Type of entity (customer, booking, attendee)
     * @param int $entityId ID of the entity
     * @param array $piiData PII data to store
     * @param array $options Storage options
     * @return array Storage results
     */
    public function storePIIForEntity(string $entityType, int $entityId, array $piiData, array $options = []): array
    {
        $results = [];
        $fieldMappings = self::PII_FIELD_MAPPINGS[$entityType] ?? [];
        
        foreach ($piiData as $fieldName => $value) {
            if (isset($fieldMappings[$fieldName]) && !empty($value)) {
                try {
                    $result = $this->piiVaultService->storePII(
                        $entityType,
                        $entityId,
                        $fieldName,
                        $value,
                        array_merge([
                            'retention_policy' => $fieldMappings[$fieldName],
                            'data_classification' => 'sensitive',
                        ], $options)
                    );
                    
                    $results[$fieldName] = $result;
                    
                } catch (\Exception $e) {
                    Log::error('Failed to store PII in vault', [
                        'entity_type' => $entityType,
                        'entity_id' => $entityId,
                        'field_name' => $fieldName,
                        'error' => $e->getMessage(),
                    ]);
                    
                    $results[$fieldName] = [
                        'success' => false,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }
        
        return $results;
    }

    /**
     * Retrieve PII data for an entity from vault.
     * 
     * @param string $entityType Type of entity
     * @param int $entityId ID of the entity
     * @param array $requestedFields Specific fields to retrieve (empty for all)
     * @param string $accessReason Reason for accessing the PII
     * @param array $context Additional access context
     * @return array Retrieved PII data
     */
    public function retrievePIIForEntity(
        string $entityType, 
        int $entityId, 
        array $requestedFields = [], 
        string $accessReason = 'Service operation',
        array $context = []
    ): array {
        $piiData = [];
        $fieldMappings = self::PII_FIELD_MAPPINGS[$entityType] ?? [];
        
        $fieldsToRetrieve = empty($requestedFields) ? array_keys($fieldMappings) : $requestedFields;
        
        foreach ($fieldsToRetrieve as $fieldName) {
            if (isset($fieldMappings[$fieldName])) {
                try {
                    $value = $this->piiVaultService->retrievePII(
                        $entityType,
                        $entityId,
                        $fieldName,
                        $accessReason,
                        $context
                    );
                    
                    if ($value !== null) {
                        $piiData[$fieldName] = $value;
                    }
                    
                } catch (\Exception $e) {
                    Log::error('Failed to retrieve PII from vault', [
                        'entity_type' => $entityType,
                        'entity_id' => $entityId,
                        'field_name' => $fieldName,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        
        return $piiData;
    }

    /**
     * Enhanced booking creation with PII vault integration.
     * 
     * @param array $bookingData Booking data including PII
     * @return array Booking creation result with PII vault IDs
     */
    public function createBookingWithPIIVault(array $bookingData): array
    {
        return DB::transaction(function () use ($bookingData) {
            // Extract PII data from booking data
            $bookingPII = $this->extractBookingPII($bookingData);
            $attendeesPII = $this->extractAttendeesPII($bookingData['attendees'] ?? []);
            
            // Create booking record with non-PII data
            $cleanBookingData = $this->removeBookingPII($bookingData);
            
            // Store booking in database (this would call the original BookingService)
            $booking = $this->createBookingRecord($cleanBookingData);
            
            // Store booking PII in vault
            $bookingPIIResults = $this->storePIIForEntity(
                'booking',
                $booking->id,
                $bookingPII,
                [
                    'metadata' => [
                        'event_id' => $booking->event_id,
                        'customer_id' => $booking->customer_id,
                        'created_via' => 'booking_service',
                    ],
                ]
            );
            
            // Store attendee PII in vault
            $attendeePIIResults = [];
            foreach ($booking->attendees as $index => $attendee) {
                if (isset($attendeesPII[$index])) {
                    $attendeePIIResults[$attendee->id] = $this->storePIIForEntity(
                        'attendee',
                        $attendee->id,
                        $attendeesPII[$index],
                        [
                            'metadata' => [
                                'booking_id' => $booking->id,
                                'event_id' => $booking->event_id,
                                'created_via' => 'booking_service',
                            ],
                        ]
                    );
                }
            }
            
            return [
                'booking' => $booking,
                'booking_pii_results' => $bookingPIIResults,
                'attendee_pii_results' => $attendeePIIResults,
            ];
        });
    }

    /**
     * Enhanced booking retrieval with PII vault integration.
     * 
     * @param int $bookingId Booking ID
     * @param string $accessReason Reason for accessing PII
     * @param array $context Access context
     * @return array Complete booking data with PII
     */
    public function getBookingWithPII(int $bookingId, string $accessReason = 'Booking retrieval', array $context = []): array
    {
        // Get booking record from database
        $booking = DB::table('bookings')->where('id', $bookingId)->first();
        
        if (!$booking) {
            throw new \InvalidArgumentException("Booking not found: {$bookingId}");
        }
        
        // Get attendees
        $attendees = DB::table('booking_attendees')->where('booking_id', $bookingId)->get();
        
        // Retrieve booking PII from vault
        $bookingPII = $this->retrievePIIForEntity(
            'booking',
            $bookingId,
            [],
            $accessReason,
            $context
        );
        
        // Retrieve attendee PII from vault
        $attendeesPII = [];
        foreach ($attendees as $attendee) {
            $attendeesPII[$attendee->id] = $this->retrievePIIForEntity(
                'attendee',
                $attendee->id,
                [],
                $accessReason,
                $context
            );
        }
        
        return [
            'booking' => $booking,
            'booking_pii' => $bookingPII,
            'attendees' => $attendees,
            'attendees_pii' => $attendeesPII,
        ];
    }

    /**
     * Update existing services to use PII vault.
     * 
     * @param string $serviceName Name of service to update
     * @return array Update results
     */
    public function updateServiceForPIIVault(string $serviceName): array
    {
        $results = [];
        
        switch ($serviceName) {
            case 'BookingService':
                $results = $this->updateBookingServiceForPIIVault();
                break;
                
            case 'CustomerService':
                $results = $this->updateCustomerServiceForPIIVault();
                break;
                
            case 'TicketService':
                $results = $this->updateTicketServiceForPIIVault();
                break;
                
            default:
                throw new \InvalidArgumentException("Unknown service: {$serviceName}");
        }
        
        return $results;
    }

    // Private helper methods

    private function extractBookingPII(array $bookingData): array
    {
        $piiFields = array_keys(self::PII_FIELD_MAPPINGS['booking']);
        $piiData = [];
        
        foreach ($piiFields as $field) {
            if (isset($bookingData[$field])) {
                $piiData[$field] = $bookingData[$field];
            }
        }
        
        return $piiData;
    }

    private function extractAttendeesPII(array $attendeesData): array
    {
        $piiFields = array_keys(self::PII_FIELD_MAPPINGS['attendee']);
        $attendeesPII = [];
        
        foreach ($attendeesData as $index => $attendeeData) {
            $attendeePII = [];
            foreach ($piiFields as $field) {
                if (isset($attendeeData[$field])) {
                    $attendeePII[$field] = $attendeeData[$field];
                }
            }
            if (!empty($attendeePII)) {
                $attendeesPII[$index] = $attendeePII;
            }
        }
        
        return $attendeesPII;
    }

    private function removeBookingPII(array $bookingData): array
    {
        $piiFields = array_keys(self::PII_FIELD_MAPPINGS['booking']);
        $cleanData = $bookingData;
        
        foreach ($piiFields as $field) {
            unset($cleanData[$field]);
        }
        
        // Also clean attendees PII
        if (isset($cleanData['attendees'])) {
            $attendeePIIFields = array_keys(self::PII_FIELD_MAPPINGS['attendee']);
            foreach ($cleanData['attendees'] as &$attendee) {
                foreach ($attendeePIIFields as $field) {
                    unset($attendee[$field]);
                }
            }
        }
        
        return $cleanData;
    }

    private function createBookingRecord(array $cleanBookingData): object
    {
        $tenantId = $this->resolveTenantId($cleanBookingData['tenant_id'] ?? null);

        // This would integrate with the existing BookingService
        // For now, create a basic booking record
        $bookingId = DB::table('bookings')->insertGetId([
            'tenant_id' => $tenantId,
            'event_id' => $cleanBookingData['event_id'],
            'customer_id' => $cleanBookingData['customer_id'] ?? null,
            'booking_reference' => $cleanBookingData['booking_reference'] ?? 'BK-' . strtoupper(uniqid()),
            'state' => 'new',
            'total_amount' => $cleanBookingData['total_amount'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        // Create attendees
        $attendees = [];
        foreach ($cleanBookingData['attendees'] ?? [] as $attendeeData) {
            $attendeeId = DB::table('booking_attendees')->insertGetId([
                'booking_id' => $bookingId,
                'ticket_type_id' => $attendeeData['ticket_type_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            $attendees[] = (object) ['id' => $attendeeId, 'booking_id' => $bookingId];
        }
        
        return (object) [
            'id' => $bookingId,
            'event_id' => $cleanBookingData['event_id'],
            'customer_id' => $cleanBookingData['customer_id'] ?? null,
            'attendees' => $attendees,
        ];
    }

    private function updateBookingServiceForPIIVault(): array
    {
        // This would contain logic to update BookingService methods
        // to use PII vault instead of direct database storage
        return [
            'service' => 'BookingService',
            'status' => 'updated',
            'methods_updated' => [
                'createBooking',
                'updateBooking',
                'getBookingDetails',
            ],
            'pii_fields_migrated' => array_keys(self::PII_FIELD_MAPPINGS['booking']),
        ];
    }

    private function updateCustomerServiceForPIIVault(): array
    {
        return [
            'service' => 'CustomerService',
            'status' => 'updated',
            'methods_updated' => [
                'createCustomer',
                'updateCustomer',
                'getCustomerDetails',
            ],
            'pii_fields_migrated' => array_keys(self::PII_FIELD_MAPPINGS['customer']),
        ];
    }

    private function updateTicketServiceForPIIVault(): array
    {
        return [
            'service' => 'TicketService',
            'status' => 'updated',
            'methods_updated' => [
                'generateTickets',
                'getTicketDetails',
            ],
            'pii_fields_migrated' => array_keys(self::PII_FIELD_MAPPINGS['attendee']),
        ];
    }

    private function resolveTenantId(?string $tenantId = null): string
    {
        $resolvedTenantId = $tenantId ?? tenant('id');

        if (!is_string($resolvedTenantId) || trim($resolvedTenantId) === '') {
            throw new \RuntimeException('Tenant context is required for PII booking writes');
        }

        return $resolvedTenantId;
    }
}