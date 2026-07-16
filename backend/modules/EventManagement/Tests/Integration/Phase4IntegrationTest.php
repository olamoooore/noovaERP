<?php

namespace Modules\EventManagement\Tests\Integration;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\EventManagement\Models\Bid;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\EventType;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Phase 4 Integration Test
 *
 * Tests end-to-end flows for:
 * - Event creation with different types and acquisition modes
 * - Direct purchase flow
 * - Bidding flow
 * - Role-based access control
 * - Sale window enforcement
 *
 * Task: 12. Checkpoint - Integration Testing
 */
class Phase4IntegrationTest extends TestCase
{
    protected $tenant;

    protected $staffUser;

    protected $customerUser;

    protected $customer;

    protected $concertType;

    protected $meetArtistType;

    protected function setUp(): void
    {
        parent::setUp();

        // Clean up database before each test
        DB::statement('DELETE FROM events');
        DB::statement('DELETE FROM bookings');
        DB::statement('DELETE FROM bids');
        DB::statement('DELETE FROM event_types');
        DB::statement('DELETE FROM customers');
        DB::statement('DELETE FROM users');
        DB::statement('DELETE FROM tenants');

        // Create tenant
        $this->tenant = Tenant::create([
            'id' => 'test-tenant',
            'name' => 'Test Tenant',
        ]);

        // Initialize tenant context
        tenancy()->initialize($this->tenant);

        // Create roles if they don't exist
        if (! Role::where('name', 'staff')->exists()) {
            Role::create(['name' => 'staff', 'guard_name' => 'web']);
        }
        if (! Role::where('name', 'customer')->exists()) {
            Role::create(['name' => 'customer', 'guard_name' => 'web']);
        }

        // Create staff user
        $this->staffUser = User::create([
            'name' => 'Staff User',
            'email' => 'staff@test.com',
            'password' => bcrypt('password'),
        ]);
        $this->staffUser->assignRole('staff');

        // Create customer user
        $this->customerUser = User::create([
            'name' => 'Customer User',
            'email' => 'customer@test.com',
            'password' => bcrypt('password'),
        ]);
        $this->customerUser->assignRole('customer');

        // Create customer record
        $this->customer = Customer::create([
            'name' => 'Test Customer',
            'email' => 'customer@test.com',
            'phone' => '1234567890',
        ]);

        // Create event types
        $this->concertType = EventType::create([
            'name' => 'Concert',
            'slug' => 'concert',
            'description' => 'Live music performance',
            'is_active' => true,
        ]);

        $this->meetArtistType = EventType::create([
            'name' => 'Meet-the-Artist',
            'slug' => 'meet-the-artist',
            'description' => 'Personal meet and greet',
            'is_active' => true,
        ]);
    }

    /**
     * Test: End-to-end event creation with Direct Purchase mode
     *
     * @test
     */
    public function test_create_event_with_direct_purchase_mode()
    {
        $this->actingAs($this->staffUser);

        $eventData = [
            'name' => 'Summer Concert 2024',
            'description' => 'Amazing summer concert',
            'venue' => 'City Arena',
            'event_date' => Carbon::now()->addDays(30)->toDateTimeString(),
            'event_type_id' => $this->concertType->id,
            'ticket_acquisition_mode' => 'buy',
            'sale_start_date' => Carbon::now()->toDateTimeString(),
            'sale_end_date' => Carbon::now()->addDays(20)->toDateTimeString(),
            'total_capacity' => 100,
            'ticket_price' => 50.00,
        ];

        $response = $this->postJson('/api/tenant/events', $eventData);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'event_type',
                'ticket_acquisition_mode',
                'sale_start_date',
                'sale_end_date',
            ],
        ]);

        $this->assertDatabaseHas('events', [
            'name' => 'Summer Concert 2024',
            'ticket_acquisition_mode' => 'buy',
        ]);
    }

    /**
     * Test: End-to-end event creation with Bidding mode
     *
     * @test
     */
    public function test_create_event_with_bidding_mode()
    {
        $this->actingAs($this->staffUser);

        $eventData = [
            'name' => 'Exclusive Meet & Greet',
            'description' => 'Meet your favorite artist',
            'venue' => 'VIP Lounge',
            'event_date' => Carbon::now()->addDays(30)->toDateTimeString(),
            'event_type_id' => $this->meetArtistType->id,
            'ticket_acquisition_mode' => 'bid',
            'sale_start_date' => Carbon::now()->toDateTimeString(),
            'sale_end_date' => Carbon::now()->addDays(20)->toDateTimeString(),
            'total_capacity' => 20,
            'ticket_price' => 100.00,
        ];

        $response = $this->postJson('/api/tenant/events', $eventData);

        $response->assertStatus(201);
        $response->assertJsonPath('data.ticket_acquisition_mode', 'bid');

        $this->assertDatabaseHas('events', [
            'name' => 'Exclusive Meet & Greet',
            'ticket_acquisition_mode' => 'bid',
        ]);
    }

    /**
     * Test: Direct purchase flow for buy-mode event
     *
     * @test
     */
    public function test_direct_purchase_flow_for_buy_mode_event()
    {
        // Create event with buy mode
        $event = Event::create([
            'name' => 'Test Concert',
            'description' => 'Test Description',
            'venue' => 'Test Venue',
            'event_date' => Carbon::now()->addDays(30),
            'event_type_id' => $this->concertType->id,
            'ticket_acquisition_mode' => 'buy',
            'sale_start_date' => Carbon::now()->subHour(),
            'sale_end_date' => Carbon::now()->addDays(20),
            'total_capacity' => 100,
            'available_capacity' => 100,
            'ticket_price' => 50.00,
        ]);

        $this->actingAs($this->customerUser);

        $purchaseData = [
            'event_id' => $event->id,
            'customer_id' => $this->customer->id,
            'quantity' => 2,
        ];

        $response = $this->postJson('/api/tenant/bookings', $purchaseData);

        $response->assertStatus(201);

        // Verify booking was created
        $this->assertDatabaseHas('bookings', [
            'event_id' => $event->id,
            'customer_id' => $this->customer->id,
            'quantity' => 2,
        ]);

        // Verify capacity was reduced
        $event->refresh();
        $this->assertEquals(98, $event->available_capacity);
    }

    /**
     * Test: Bidding flow for bid-mode event
     *
     * @test
     */
    public function test_bidding_flow_for_bid_mode_event()
    {
        // Create event with bid mode
        $event = Event::create([
            'name' => 'VIP Meet & Greet',
            'description' => 'Exclusive event',
            'venue' => 'VIP Lounge',
            'event_date' => Carbon::now()->addDays(30),
            'event_type_id' => $this->meetArtistType->id,
            'ticket_acquisition_mode' => 'bid',
            'sale_start_date' => Carbon::now()->subHour(),
            'sale_end_date' => Carbon::now()->addDays(20),
            'total_capacity' => 20,
            'available_capacity' => 20,
            'ticket_price' => 100.00,
        ]);

        $this->actingAs($this->customerUser);

        $bidData = [
            'event_id' => $event->id,
            'customer_id' => $this->customer->id,
            'bid_amount' => 150.00,
            'quantity' => 1,
        ];

        $response = $this->postJson('/api/tenant/bids', $bidData);

        $response->assertStatus(201);

        // Verify bid was created
        $this->assertDatabaseHas('bids', [
            'event_id' => $event->id,
            'customer_id' => $this->customer->id,
            'bid_amount' => 150.00,
            'status' => 'pending',
        ]);
    }

    /**
     * Test: Purchase prevented for bidding-only event
     *
     * @test
     */
    public function test_purchase_prevented_for_bidding_only_event()
    {
        $event = Event::create([
            'name' => 'Bid Only Event',
            'description' => 'Test Description',
            'venue' => 'Test Venue',
            'event_date' => Carbon::now()->addDays(30),
            'event_type_id' => $this->meetArtistType->id,
            'ticket_acquisition_mode' => 'bid',
            'sale_start_date' => Carbon::now()->subHour(),
            'sale_end_date' => Carbon::now()->addDays(20),
            'total_capacity' => 20,
            'available_capacity' => 20,
            'ticket_price' => 100.00,
        ]);

        $this->actingAs($this->customerUser);

        $purchaseData = [
            'event_id' => $event->id,
            'customer_id' => $this->customer->id,
            'quantity' => 1,
        ];

        $response = $this->postJson('/api/tenant/bookings', $purchaseData);

        $response->assertStatus(400);
        $response->assertJsonPath('error.message', 'This event only supports bidding. Cannot purchase directly.');
    }

    /**
     * Test: Bidding prevented for purchase-only event
     *
     * @test
     */
    public function test_bidding_prevented_for_purchase_only_event()
    {
        $event = Event::create([
            'name' => 'Purchase Only Event',
            'description' => 'Test Description',
            'venue' => 'Test Venue',
            'event_date' => Carbon::now()->addDays(30),
            'event_type_id' => $this->concertType->id,
            'ticket_acquisition_mode' => 'buy',
            'sale_start_date' => Carbon::now()->subHour(),
            'sale_end_date' => Carbon::now()->addDays(20),
            'total_capacity' => 100,
            'available_capacity' => 100,
            'ticket_price' => 50.00,
        ]);

        $this->actingAs($this->customerUser);

        $bidData = [
            'event_id' => $event->id,
            'customer_id' => $this->customer->id,
            'bid_amount' => 75.00,
            'quantity' => 1,
        ];

        $response = $this->postJson('/api/tenant/bids', $bidData);

        $response->assertStatus(400);
        $response->assertJsonPath('error.message', 'This event only supports direct purchase. Cannot bid.');
    }

    /**
     * Test: Sale window enforcement - before start
     *
     * @test
     */
    public function test_purchase_prevented_before_sale_window()
    {
        $event = Event::create([
            'name' => 'Future Sale Event',
            'description' => 'Test Description',
            'venue' => 'Test Venue',
            'event_date' => Carbon::now()->addDays(30),
            'event_type_id' => $this->concertType->id,
            'ticket_acquisition_mode' => 'buy',
            'sale_start_date' => Carbon::now()->addDays(5),
            'sale_end_date' => Carbon::now()->addDays(20),
            'total_capacity' => 100,
            'available_capacity' => 100,
            'ticket_price' => 50.00,
        ]);

        $this->actingAs($this->customerUser);

        $purchaseData = [
            'event_id' => $event->id,
            'customer_id' => $this->customer->id,
            'quantity' => 1,
        ];

        $response = $this->postJson('/api/tenant/bookings', $purchaseData);

        $response->assertStatus(403);
        $response->assertJsonFragment(['error' => 'Ticket sales have not started yet']);
    }

    /**
     * Test: Sale window enforcement - after end
     *
     * @test
     */
    public function test_purchase_prevented_after_sale_window()
    {
        $event = Event::create([
            'name' => 'Past Sale Event',
            'description' => 'Test Description',
            'venue' => 'Test Venue',
            'event_date' => Carbon::now()->addDays(30),
            'event_type_id' => $this->concertType->id,
            'ticket_acquisition_mode' => 'buy',
            'sale_start_date' => Carbon::now()->subDays(20),
            'sale_end_date' => Carbon::now()->subDays(5),
            'total_capacity' => 100,
            'available_capacity' => 100,
            'ticket_price' => 50.00,
        ]);

        $this->actingAs($this->customerUser);

        $purchaseData = [
            'event_id' => $event->id,
            'customer_id' => $this->customer->id,
            'quantity' => 1,
        ];

        $response = $this->postJson('/api/tenant/bookings', $purchaseData);

        $response->assertStatus(403);
        $response->assertJsonFragment(['error' => 'Ticket sales have ended']);
    }

    /**
     * Test: Customer cannot see inventory data
     *
     * @test
     */
    public function test_customer_cannot_see_inventory_data()
    {
        $event = Event::create([
            'name' => 'Test Event',
            'description' => 'Test Description',
            'venue' => 'Test Venue',
            'event_date' => Carbon::now()->addDays(30),
            'event_type_id' => $this->concertType->id,
            'ticket_acquisition_mode' => 'buy',
            'sale_start_date' => Carbon::now()->subHour(),
            'sale_end_date' => Carbon::now()->addDays(20),
            'total_capacity' => 100,
            'available_capacity' => 75,
            'ticket_price' => 50.00,
        ]);

        $this->actingAs($this->customerUser);

        $response = $this->getJson("/api/tenant/events/{$event->id}");

        $response->assertStatus(200);
        $response->assertJsonMissing(['total_capacity']);
        $response->assertJsonMissing(['available_capacity']);
        $response->assertJsonMissing(['inventory']);
    }

    /**
     * Test: Staff can see inventory data
     *
     * @test
     */
    public function test_staff_can_see_inventory_data()
    {
        $event = Event::create([
            'name' => 'Test Event',
            'description' => 'Test Description',
            'venue' => 'Test Venue',
            'event_date' => Carbon::now()->addDays(30),
            'event_type_id' => $this->concertType->id,
            'ticket_acquisition_mode' => 'buy',
            'sale_start_date' => Carbon::now()->subHour(),
            'sale_end_date' => Carbon::now()->addDays(20),
            'total_capacity' => 100,
            'available_capacity' => 75,
            'ticket_price' => 50.00,
        ]);

        $this->actingAs($this->staffUser);

        $response = $this->getJson("/api/tenant/events/{$event->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.inventory.total', 100);
        $response->assertJsonPath('data.inventory.remaining', 75);
        $response->assertJsonPath('data.inventory.sold', 25);
    }

    /**
     * Test: Customer cannot access staff-only endpoints
     *
     * @test
     */
    public function test_customer_cannot_access_staff_endpoints()
    {
        $event = Event::create([
            'name' => 'Test Event',
            'description' => 'Test Description',
            'venue' => 'Test Venue',
            'event_date' => Carbon::now()->addDays(30),
            'event_type_id' => $this->meetArtistType->id,
            'ticket_acquisition_mode' => 'bid',
            'sale_start_date' => Carbon::now()->subHour(),
            'sale_end_date' => Carbon::now()->addDays(20),
            'total_capacity' => 20,
            'available_capacity' => 20,
            'ticket_price' => 100.00,
        ]);

        $this->actingAs($this->customerUser);

        // Try to access bids list (staff only)
        $response = $this->getJson("/api/tenant/events/{$event->id}/bids");

        $response->assertStatus(403);
    }

    /**
     * Test: Staff can access bid management endpoints
     *
     * @test
     */
    public function test_staff_can_access_bid_management()
    {
        $event = Event::create([
            'name' => 'Test Event',
            'description' => 'Test Description',
            'venue' => 'Test Venue',
            'event_date' => Carbon::now()->addDays(30),
            'event_type_id' => $this->meetArtistType->id,
            'ticket_acquisition_mode' => 'bid',
            'sale_start_date' => Carbon::now()->subHour(),
            'sale_end_date' => Carbon::now()->addDays(20),
            'total_capacity' => 20,
            'available_capacity' => 20,
            'ticket_price' => 100.00,
        ]);

        // Create a bid
        Bid::create([
            'event_id' => $event->id,
            'customer_id' => $this->customer->id,
            'bid_amount' => 150.00,
            'quantity' => 1,
            'status' => 'pending',
        ]);

        $this->actingAs($this->staffUser);

        $response = $this->getJson("/api/tenant/events/{$event->id}/bids");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
    }

    /**
     * Test: Event validation - missing event type
     *
     * @test
     */
    public function test_event_creation_fails_without_event_type()
    {
        $this->actingAs($this->staffUser);

        $eventData = [
            'name' => 'Invalid Event',
            'description' => 'Missing event type',
            'venue' => 'Test Venue',
            'event_date' => Carbon::now()->addDays(30)->toDateTimeString(),
            // event_type_id missing
            'ticket_acquisition_mode' => 'buy',
            'sale_start_date' => Carbon::now()->toDateTimeString(),
            'sale_end_date' => Carbon::now()->addDays(20)->toDateTimeString(),
            'total_capacity' => 100,
            'ticket_price' => 50.00,
        ];

        $response = $this->postJson('/api/tenant/events', $eventData);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['event_type_id']);
    }

    /**
     * Test: Event validation - missing acquisition mode
     *
     * @test
     */
    public function test_event_creation_fails_without_acquisition_mode()
    {
        $this->actingAs($this->staffUser);

        $eventData = [
            'name' => 'Invalid Event',
            'description' => 'Missing acquisition mode',
            'venue' => 'Test Venue',
            'event_date' => Carbon::now()->addDays(30)->toDateTimeString(),
            'event_type_id' => $this->concertType->id,
            // ticket_acquisition_mode missing
            'sale_start_date' => Carbon::now()->toDateTimeString(),
            'sale_end_date' => Carbon::now()->addDays(20)->toDateTimeString(),
            'total_capacity' => 100,
            'ticket_price' => 50.00,
        ];

        $response = $this->postJson('/api/tenant/events', $eventData);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ticket_acquisition_mode']);
    }

    /**
     * Test: Event validation - invalid sale window
     *
     * @test
     */
    public function test_event_creation_fails_with_invalid_sale_window()
    {
        $this->actingAs($this->staffUser);

        $eventData = [
            'name' => 'Invalid Event',
            'description' => 'Invalid sale window',
            'venue' => 'Test Venue',
            'event_date' => Carbon::now()->addDays(30)->toDateTimeString(),
            'event_type_id' => $this->concertType->id,
            'ticket_acquisition_mode' => 'buy',
            'sale_start_date' => Carbon::now()->addDays(20)->toDateTimeString(),
            'sale_end_date' => Carbon::now()->toDateTimeString(), // End before start
            'total_capacity' => 100,
            'ticket_price' => 50.00,
        ];

        $response = $this->postJson('/api/tenant/events', $eventData);

        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => 'Sale end date must be after sale start date']);
    }

    /**
     * Test: Complete purchase workflow with capacity reduction
     *
     * @test
     */
    public function test_complete_purchase_workflow_reduces_capacity()
    {
        $event = Event::create([
            'name' => 'Capacity Test Event',
            'description' => 'Test Description',
            'venue' => 'Test Venue',
            'event_date' => Carbon::now()->addDays(30),
            'event_type_id' => $this->concertType->id,
            'ticket_acquisition_mode' => 'buy',
            'sale_start_date' => Carbon::now()->subHour(),
            'sale_end_date' => Carbon::now()->addDays(20),
            'total_capacity' => 100,
            'available_capacity' => 100,
            'ticket_price' => 50.00,
        ]);

        $this->actingAs($this->customerUser);

        // First purchase
        $this->postJson('/api/tenant/bookings', [
            'event_id' => $event->id,
            'customer_id' => $this->customer->id,
            'quantity' => 10,
        ])->assertStatus(201);

        $event->refresh();
        $this->assertEquals(90, $event->available_capacity);

        // Second purchase
        $this->postJson('/api/tenant/bookings', [
            'event_id' => $event->id,
            'customer_id' => $this->customer->id,
            'quantity' => 5,
        ])->assertStatus(201);

        $event->refresh();
        $this->assertEquals(85, $event->available_capacity);
    }

    /**
     * Test: Backward compatibility - existing events work
     *
     * @test
     */
    public function test_backward_compatibility_with_existing_events()
    {
        // Simulate an existing event (migrated with defaults)
        $event = Event::create([
            'name' => 'Legacy Concert',
            'description' => 'Existing event',
            'venue' => 'Old Venue',
            'event_date' => Carbon::now()->addDays(30),
            'event_type_id' => $this->concertType->id, // Migrated to Concert
            'ticket_acquisition_mode' => 'buy', // Migrated to buy
            'sale_start_date' => Carbon::now()->subHour(),
            'sale_end_date' => Carbon::now()->addDays(20),
            'total_capacity' => 100,
            'available_capacity' => 100,
            'ticket_price' => 50.00,
        ]);

        $this->actingAs($this->customerUser);

        // Should be able to purchase as before
        $response = $this->postJson('/api/tenant/bookings', [
            'event_id' => $event->id,
            'customer_id' => $this->customer->id,
            'quantity' => 2,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('bookings', [
            'event_id' => $event->id,
            'quantity' => 2,
        ]);
    }
}
