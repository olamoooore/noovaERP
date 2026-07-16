<?php

namespace Modules\EventManagement\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\EventTicketType;
use Modules\EventManagement\Services\AuditService;
use Modules\EventManagement\Services\BookingService;
use Modules\EventManagement\Services\DataEncryptionService;
use Modules\EventManagement\Services\EventService;
use Modules\EventManagement\Services\TenantSchemaManager;
use Tests\TestCase;

/**
 * Property Test for Personal Data Collection and Security
 *
 * **Feature: erp-event-management, Property 6: Personal Data Collection and Security**
 * **Validates: Requirements 4.1, 4.2, 4.3, 4.4, 4.5**
 *
 * Property: For any booking, the system should capture required buyer information
 * (name, email, phone), support multiple attendees, store consent flags, and encrypt
 * sensitive ID information when present.
 */
class PersonalDataCollectionPropertyTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $bookingService;

    private EventService $eventService;

    private DataEncryptionService $encryptionService;

    private AuditService $auditService;

    private TenantSchemaManager $tenantSchemaManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations to set up the database schema
        $this->artisan('migrate', [
            '--path' => 'backend/modules/EventManagement/Database/Migrations',
            '--realpath' => true,
        ]);

        // Set up service dependencies
        $this->auditService = $this->createMock(AuditService::class);
        $this->tenantSchemaManager = $this->createMock(TenantSchemaManager::class);
        $this->encryptionService = new DataEncryptionService;

        $this->eventService = new EventService(
            $this->tenantSchemaManager,
            $this->auditService
        );

        $this->bookingService = new BookingService(
            $this->tenantSchemaManager,
            $this->auditService
        );
    }

    /**
     * Property Test: Buyer information is properly captured and validated
     * Tests that required buyer information is captured for all bookings
     *
     * @test
     */
    public function property_buyer_information_is_properly_captured_and_validated()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runBuyerInformationTest();
        }
    }

    /**
     * Property Test: Multiple attendees are supported per booking
     * Tests that bookings can have multiple attendees with proper data collection
     *
     * @test
     */
    public function property_multiple_attendees_are_supported_per_booking()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runMultipleAttendeesTest();
        }
    }

    /**
     * Property Test: Consent flags are properly stored and enforced
     * Tests that PDPA and marketing consent flags are handled correctly
     *
     * @test
     */
    public function property_consent_flags_are_properly_stored_and_enforced()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runConsentFlagsTest();
        }
    }

    /**
     * Property Test: Sensitive ID information is encrypted
     * Tests that ID information is properly encrypted when stored
     *
     * @test
     */
    public function property_sensitive_id_information_is_encrypted()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runIdEncryptionTest();
        }
    }

    /**
     * Property Test: Optional ID fields work correctly
     * Tests that ID fields are optional and handled properly when present or absent
     *
     * @test
     */
    public function property_optional_id_fields_work_correctly()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runOptionalIdFieldsTest();
        }
    }

    /**
     * Run a single iteration of buyer information testing
     */
    private function runBuyerInformationTest(): void
    {
        // Create test event and ticket type
        $event = $this->createTestEvent();
        $ticketType = $this->createTestTicketType($event);

        // Generate random buyer data
        $buyerData = $this->generateRandomBuyerData();
        $attendeeData = $this->generateRandomAttendeeData($ticketType->id);

        $bookingData = array_merge($buyerData, [
            'event_id' => $event->id,
            'total_amount' => $ticketType->price,
            'currency' => 'USD',
            'attendees' => [$attendeeData],
        ]);

        // Create booking
        $booking = $this->bookingService->createBooking($bookingData);

        // Verify buyer information is properly stored
        $this->assertInstanceOf(Booking::class, $booking);
        $this->assertEquals($buyerData['buyer_name'], $booking->buyer_name);
        $this->assertEquals($buyerData['buyer_email'], $booking->buyer_email);
        $this->assertEquals($buyerData['buyer_phone'], $booking->buyer_phone);
        $this->assertEquals($buyerData['consent_pdpa'], $booking->consent_pdpa);
        $this->assertEquals($buyerData['consent_marketing'], $booking->consent_marketing);

        // Verify buyer information meets validation requirements
        $this->assertGreaterThanOrEqual(2, strlen($booking->buyer_name), 'Buyer name should be at least 2 characters');
        $this->assertMatchesRegularExpression('/^[^\@]+@[^\@]+\.[^\@]+$/', $booking->buyer_email, 'Buyer email should be valid');
        $this->assertMatchesRegularExpression('/^[\+]?[0-9\-\(\)\s]+$/', $booking->buyer_phone, 'Buyer phone should be valid');
        $this->assertTrue($booking->consent_pdpa, 'PDPA consent should be required and true');

        // Verify database storage
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'buyer_name' => $buyerData['buyer_name'],
            'buyer_email' => $buyerData['buyer_email'],
            'buyer_phone' => $buyerData['buyer_phone'],
            'consent_pdpa' => $buyerData['consent_pdpa'],
            'consent_marketing' => $buyerData['consent_marketing'],
        ]);
    }

    /**
     * Run a single iteration of multiple attendees testing
     */
    private function runMultipleAttendeesTest(): void
    {
        // Create test event and ticket type
        $event = $this->createTestEvent();
        $ticketType = $this->createTestTicketType($event);

        // Generate random number of attendees (1-5)
        $numAttendees = rand(1, 5);
        $attendees = [];
        $totalAmount = 0;

        for ($i = 0; $i < $numAttendees; $i++) {
            $attendees[] = $this->generateRandomAttendeeData($ticketType->id);
            $totalAmount += $ticketType->price;
        }

        $bookingData = array_merge($this->generateRandomBuyerData(), [
            'event_id' => $event->id,
            'total_amount' => $totalAmount,
            'currency' => 'USD',
            'attendees' => $attendees,
        ]);

        // Create booking
        $booking = $this->bookingService->createBooking($bookingData);

        // Verify all attendees are created
        $this->assertCount($numAttendees, $booking->attendees);

        // Verify each attendee data
        foreach ($booking->attendees as $index => $attendee) {
            $expectedAttendee = $attendees[$index];

            $this->assertEquals($expectedAttendee['attendee_name'], $attendee->attendee_name);
            $this->assertEquals($expectedAttendee['attendee_email'] ?? null, $attendee->attendee_email);
            $this->assertEquals($expectedAttendee['attendee_phone'] ?? null, $attendee->attendee_phone);
            $this->assertEquals($ticketType->id, $attendee->ticket_type_id);

            // Verify attendee name validation
            $this->assertGreaterThanOrEqual(2, strlen($attendee->attendee_name), 'Attendee name should be at least 2 characters');

            // Verify optional email validation
            if ($attendee->attendee_email) {
                $this->assertMatchesRegularExpression('/^[^\@]+@[^\@]+\.[^\@]+$/', $attendee->attendee_email, 'Attendee email should be valid when provided');
            }

            // Verify optional phone validation
            if ($attendee->attendee_phone) {
                $this->assertMatchesRegularExpression('/^[\+]?[0-9\-\(\)\s]+$/', $attendee->attendee_phone, 'Attendee phone should be valid when provided');
            }
        }

        // Verify database storage
        $this->assertEquals($numAttendees, DB::table('booking_attendees')->where('booking_id', $booking->id)->count());
    }

    /**
     * Run a single iteration of consent flags testing
     */
    private function runConsentFlagsTest(): void
    {
        // Create test event and ticket type
        $event = $this->createTestEvent();
        $ticketType = $this->createTestTicketType($event);

        // Test with PDPA consent required (true) and random marketing consent
        $buyerData = $this->generateRandomBuyerData();
        $buyerData['consent_pdpa'] = true; // Always required
        $buyerData['consent_marketing'] = (bool) rand(0, 1); // Random

        $bookingData = array_merge($buyerData, [
            'event_id' => $event->id,
            'total_amount' => $ticketType->price,
            'currency' => 'USD',
            'attendees' => [$this->generateRandomAttendeeData($ticketType->id)],
        ]);

        // Create booking
        $booking = $this->bookingService->createBooking($bookingData);

        // Verify consent flags are stored correctly
        $this->assertTrue($booking->consent_pdpa, 'PDPA consent must be true');
        $this->assertEquals($buyerData['consent_marketing'], $booking->consent_marketing);

        // Verify consent flags are boolean type
        $this->assertIsBool($booking->consent_pdpa);
        $this->assertIsBool($booking->consent_marketing);

        // Verify database storage with correct types
        $dbBooking = DB::table('bookings')->where('id', $booking->id)->first();
        $this->assertEquals(1, $dbBooking->consent_pdpa); // True stored as 1
        $this->assertEquals($buyerData['consent_marketing'] ? 1 : 0, $dbBooking->consent_marketing);
    }

    /**
     * Run a single iteration of ID encryption testing
     */
    private function runIdEncryptionTest(): void
    {
        // Create test event and ticket type
        $event = $this->createTestEvent();
        $ticketType = $this->createTestTicketType($event);

        // Generate attendee data with ID information
        $attendeeData = $this->generateRandomAttendeeData($ticketType->id);
        $originalIdInfo = $this->generateRandomIdInfo();
        $attendeeData['encrypted_id_info'] = $originalIdInfo;

        $bookingData = array_merge($this->generateRandomBuyerData(), [
            'event_id' => $event->id,
            'total_amount' => $ticketType->price,
            'currency' => 'USD',
            'attendees' => [$attendeeData],
        ]);

        // Create booking
        $booking = $this->bookingService->createBooking($bookingData);
        $attendee = $booking->attendees->first();

        // Verify ID information is encrypted in database
        $dbAttendee = DB::table('booking_attendees')->where('id', $attendee->id)->first();
        $this->assertNotEquals($originalIdInfo, $dbAttendee->encrypted_id_info, 'ID info should be encrypted in database');
        $this->assertNotEmpty($dbAttendee->encrypted_id_info, 'Encrypted ID info should not be empty');

        // Verify ID information can be decrypted correctly
        $decryptedIdInfo = $attendee->getIdInformation();
        $this->assertEquals($originalIdInfo, $decryptedIdInfo, 'ID info should decrypt to original value');

        // Verify encryption service works correctly
        $testEncryption = $this->encryptionService->encryptIdInformation($originalIdInfo);
        $testDecryption = $this->encryptionService->decryptIdInformation($testEncryption);
        $this->assertEquals($originalIdInfo, $testDecryption, 'Encryption service should work correctly');

        // Verify attendee has ID information
        $this->assertTrue($attendee->hasIdInformation(), 'Attendee should have ID information');
    }

    /**
     * Run a single iteration of optional ID fields testing
     */
    private function runOptionalIdFieldsTest(): void
    {
        // Create test event and ticket type
        $event = $this->createTestEvent();
        $ticketType = $this->createTestTicketType($event);

        // Test with and without ID information
        $withIdData = $this->generateRandomAttendeeData($ticketType->id);
        $withIdData['encrypted_id_info'] = $this->generateRandomIdInfo();

        $withoutIdData = $this->generateRandomAttendeeData($ticketType->id);
        // Explicitly don't set encrypted_id_info

        $bookingData = array_merge($this->generateRandomBuyerData(), [
            'event_id' => $event->id,
            'total_amount' => $ticketType->price * 2,
            'currency' => 'USD',
            'attendees' => [$withIdData, $withoutIdData],
        ]);

        // Create booking
        $booking = $this->bookingService->createBooking($bookingData);

        $attendeeWithId = $booking->attendees->first();
        $attendeeWithoutId = $booking->attendees->last();

        // Verify attendee with ID information
        $this->assertTrue($attendeeWithId->hasIdInformation(), 'First attendee should have ID information');
        $this->assertNotNull($attendeeWithId->getIdInformation(), 'First attendee should return ID information');

        // Verify attendee without ID information
        $this->assertFalse($attendeeWithoutId->hasIdInformation(), 'Second attendee should not have ID information');
        $this->assertNull($attendeeWithoutId->getIdInformation(), 'Second attendee should return null for ID information');

        // Verify database storage
        $dbAttendeeWithId = DB::table('booking_attendees')->where('id', $attendeeWithId->id)->first();
        $dbAttendeeWithoutId = DB::table('booking_attendees')->where('id', $attendeeWithoutId->id)->first();

        $this->assertNotNull($dbAttendeeWithId->encrypted_id_info, 'Database should store encrypted ID info');
        $this->assertNull($dbAttendeeWithoutId->encrypted_id_info, 'Database should store null for missing ID info');
    }

    /**
     * Create a test event
     */
    private function createTestEvent(): Event
    {
        return Event::create([
            'title' => 'Test Event '.rand(1000, 9999),
            'description' => 'Test event for property testing',
            'event_date' => Carbon::now()->addDays(rand(1, 30)),
            'venue' => 'Test Venue',
            'total_capacity' => rand(100, 500),
            'available_capacity' => rand(100, 500),
            'status' => 'published',
        ]);
    }

    /**
     * Create a test ticket type
     */
    private function createTestTicketType(Event $event): EventTicketType
    {
        return EventTicketType::create([
            'event_id' => $event->id,
            'name' => 'Test Ticket',
            'price' => rand(25, 200) + (rand(0, 99) / 100),
            'capacity' => rand(50, 200),
            'available_capacity' => rand(50, 200),
            'description' => 'Test ticket type',
        ]);
    }

    /**
     * Generate random buyer data
     */
    private function generateRandomBuyerData(): array
    {
        $names = ['John Doe', 'Jane Smith', 'Alice Johnson', 'Bob Wilson', 'Carol Brown', 'David Lee', 'Emma Davis'];
        $domains = ['example.com', 'test.org', 'sample.net', 'demo.co'];

        $name = $names[array_rand($names)];
        $email = strtolower(str_replace(' ', '.', $name)).rand(1, 999).'@'.$domains[array_rand($domains)];
        $phone = '+'.rand(1, 99).'-'.rand(100, 999).'-'.rand(1000, 9999);

        return [
            'buyer_name' => $name,
            'buyer_email' => $email,
            'buyer_phone' => $phone,
            'consent_pdpa' => true, // Always required
            'consent_marketing' => (bool) rand(0, 1),
        ];
    }

    /**
     * Generate random attendee data
     */
    private function generateRandomAttendeeData(int $ticketTypeId): array
    {
        $names = ['Alex Kim', 'Sam Park', 'Jordan Lee', 'Taylor Wong', 'Casey Chen', 'Riley Zhang'];
        $domains = ['attendee.com', 'guest.org', 'visitor.net'];

        $name = $names[array_rand($names)];
        $hasEmail = rand(0, 1); // 50% chance of having email
        $hasPhone = rand(0, 1); // 50% chance of having phone

        $data = [
            'ticket_type_id' => $ticketTypeId,
            'attendee_name' => $name,
        ];

        if ($hasEmail) {
            $data['attendee_email'] = strtolower(str_replace(' ', '.', $name)).rand(1, 999).'@'.$domains[array_rand($domains)];
        }

        if ($hasPhone) {
            $data['attendee_phone'] = '+'.rand(1, 99).'-'.rand(100, 999).'-'.rand(1000, 9999);
        }

        return $data;
    }

    /**
     * Generate random ID information
     */
    private function generateRandomIdInfo(): string
    {
        $prefixes = ['ID', 'NRIC', 'PASSPORT', 'DL'];
        $prefix = $prefixes[array_rand($prefixes)];
        $number = rand(100000, 999999);
        $suffix = chr(rand(65, 90)); // Random uppercase letter

        return $prefix.$number.$suffix;
    }
}
