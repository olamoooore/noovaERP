<?php

namespace Modules\EventManagement\Tests\Unit;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Property Test for Database Integrity and Constraints
 *
 * **Feature: erp-event-management, Property 11: Database Integrity and Constraints**
 * **Validates: Requirements 9.2, 9.4**
 *
 * Property: For any database operation, the system should enforce foreign key constraints,
 * unique constraints on critical fields (like ticket codes), and maintain referential
 * integrity across tenant schemas.
 */
class DatabaseConstraintsPropertyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations to set up the database schema
        $this->artisan('migrate', [
            '--path' => 'backend/modules/EventManagement/Database/Migrations',
            '--realpath' => true,
        ]);
    }

    /**
     * Property Test: Foreign key constraints are enforced
     * Tests that foreign key relationships prevent orphaned records
     *
     * @test
     */
    public function property_foreign_key_constraints_are_enforced()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runForeignKeyConstraintTest();
        }
    }

    /**
     * Property Test: Unique constraints prevent duplicates
     * Tests that unique constraints on critical fields are enforced
     *
     * @test
     */
    public function property_unique_constraints_prevent_duplicates()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runUniqueConstraintTest();
        }
    }

    /**
     * Property Test: Referential integrity is maintained
     * Tests that cascading deletes work correctly
     *
     * @test
     */
    public function property_referential_integrity_is_maintained()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runReferentialIntegrityTest();
        }
    }

    /**
     * Property Test: Database schema structure is correct
     * Tests that all required tables, columns, and indexes exist
     *
     * @test
     */
    public function property_database_schema_structure_is_correct()
    {
        // Test that all required tables exist
        $requiredTables = [
            'events',
            'event_ticket_types',
            'bookings',
            'booking_attendees',
            'tickets',
            'event_settings',
            'activity_logs',
        ];

        foreach ($requiredTables as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "Required table '{$table}' does not exist"
            );
        }

        // Test events table structure
        $this->assertTrue(Schema::hasColumns('events', [
            'id', 'title', 'description', 'event_date', 'venue',
            'total_capacity', 'available_capacity', 'status', 'terms_conditions',
        ]));

        // Test bookings table structure
        $this->assertTrue(Schema::hasColumns('bookings', [
            'id', 'booking_reference', 'event_id', 'buyer_name', 'buyer_email',
            'buyer_phone', 'total_amount', 'currency', 'state', 'payment_reference',
            'consent_pdpa', 'consent_marketing',
        ]));

        // Test tickets table structure
        $this->assertTrue(Schema::hasColumns('tickets', [
            'id', 'ticket_code', 'booking_id', 'attendee_id', 'qr_payload',
            'status', 'issued_at', 'revoked_at', 'used_at',
        ]));

        // Test event_settings table structure
        $this->assertTrue(Schema::hasColumns('event_settings', [
            'id', 'tenant_id', 'currency', 'timezone', 'cancellation_policy',
            'refund_policy', 'max_capacity_per_event', 'require_attendee_id',
            'data_retention_days',
        ]));

        // Test activity_logs table structure
        $this->assertTrue(Schema::hasColumns('activity_logs', [
            'id', 'tenant_id', 'actor_type', 'actor_id', 'action',
            'subject_type', 'subject_id', 'changes', 'request_id',
            'ip_address', 'user_agent', 'created_at',
        ]));
    }

    /**
     * Run a single iteration of foreign key constraint testing
     */
    private function runForeignKeyConstraintTest(): void
    {
        // Create test event
        $eventId = DB::table('events')->insertGetId([
            'title' => 'Test Event '.rand(1000, 9999),
            'description' => 'Test Description',
            'event_date' => now()->addDays(rand(1, 30)),
            'venue' => 'Test Venue',
            'total_capacity' => rand(100, 1000),
            'available_capacity' => rand(50, 500),
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Test 1: Try to create booking with non-existent event_id
        $nonExistentEventId = $eventId + 999999;

        $this->expectException(QueryException::class);
        DB::table('bookings')->insert([
            'booking_reference' => 'BK'.rand(100000, 999999),
            'event_id' => $nonExistentEventId,
            'buyer_name' => 'Test Buyer',
            'buyer_email' => 'test@example.com',
            'buyer_phone' => '+1234567890',
            'total_amount' => 100.00,
            'currency' => 'USD',
            'state' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Run a single iteration of unique constraint testing
     */
    private function runUniqueConstraintTest(): void
    {
        // Create test event
        $eventId = DB::table('events')->insertGetId([
            'title' => 'Test Event '.rand(1000, 9999),
            'description' => 'Test Description',
            'event_date' => now()->addDays(rand(1, 30)),
            'venue' => 'Test Venue',
            'total_capacity' => rand(100, 1000),
            'available_capacity' => rand(50, 500),
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create test booking
        $bookingReference = 'BK'.rand(100000, 999999);
        $bookingId = DB::table('bookings')->insertGetId([
            'booking_reference' => $bookingReference,
            'event_id' => $eventId,
            'buyer_name' => 'Test Buyer',
            'buyer_email' => 'test@example.com',
            'buyer_phone' => '+1234567890',
            'total_amount' => 100.00,
            'currency' => 'USD',
            'state' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Test 1: Try to create another booking with same booking_reference
        $this->expectException(QueryException::class);
        DB::table('bookings')->insert([
            'booking_reference' => $bookingReference, // Same reference
            'event_id' => $eventId,
            'buyer_name' => 'Another Buyer',
            'buyer_email' => 'another@example.com',
            'buyer_phone' => '+1234567891',
            'total_amount' => 150.00,
            'currency' => 'USD',
            'state' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Run a single iteration of referential integrity testing
     */
    private function runReferentialIntegrityTest(): void
    {
        // Create test event
        $eventId = DB::table('events')->insertGetId([
            'title' => 'Test Event '.rand(1000, 9999),
            'description' => 'Test Description',
            'event_date' => now()->addDays(rand(1, 30)),
            'venue' => 'Test Venue',
            'total_capacity' => rand(100, 1000),
            'available_capacity' => rand(50, 500),
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create test ticket type
        $ticketTypeId = DB::table('event_ticket_types')->insertGetId([
            'event_id' => $eventId,
            'name' => 'General Admission',
            'price' => 50.00,
            'capacity' => rand(50, 200),
            'available_capacity' => rand(25, 100),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create test booking
        $bookingId = DB::table('bookings')->insertGetId([
            'booking_reference' => 'BK'.rand(100000, 999999),
            'event_id' => $eventId,
            'buyer_name' => 'Test Buyer',
            'buyer_email' => 'test@example.com',
            'buyer_phone' => '+1234567890',
            'total_amount' => 100.00,
            'currency' => 'USD',
            'state' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create test attendee
        $attendeeId = DB::table('booking_attendees')->insertGetId([
            'booking_id' => $bookingId,
            'ticket_type_id' => $ticketTypeId,
            'attendee_name' => 'Test Attendee',
            'attendee_email' => 'attendee@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create test ticket
        DB::table('tickets')->insert([
            'ticket_code' => 'TK'.rand(1000000, 9999999),
            'booking_id' => $bookingId,
            'attendee_id' => $attendeeId,
            'qr_payload' => 'test_qr_payload_'.rand(1000, 9999),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Test cascading delete: Delete event should cascade to related records
        $initialBookingCount = DB::table('bookings')->where('event_id', $eventId)->count();
        $initialTicketTypeCount = DB::table('event_ticket_types')->where('event_id', $eventId)->count();
        $initialAttendeeCount = DB::table('booking_attendees')->where('booking_id', $bookingId)->count();
        $initialTicketCount = DB::table('tickets')->where('booking_id', $bookingId)->count();

        // Verify records exist before deletion
        $this->assertGreaterThan(0, $initialBookingCount);
        $this->assertGreaterThan(0, $initialTicketTypeCount);
        $this->assertGreaterThan(0, $initialAttendeeCount);
        $this->assertGreaterThan(0, $initialTicketCount);

        // Delete the event - should cascade to all related records
        DB::table('events')->where('id', $eventId)->delete();

        // Verify cascading delete worked
        $this->assertEquals(0, DB::table('bookings')->where('event_id', $eventId)->count());
        $this->assertEquals(0, DB::table('event_ticket_types')->where('event_id', $eventId)->count());
        $this->assertEquals(0, DB::table('booking_attendees')->where('booking_id', $bookingId)->count());
        $this->assertEquals(0, DB::table('tickets')->where('booking_id', $bookingId)->count());
    }
}
