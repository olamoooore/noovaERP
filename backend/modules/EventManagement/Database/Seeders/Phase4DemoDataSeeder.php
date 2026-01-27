<?php

namespace Modules\EventManagement\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\EventType;
use Modules\EventManagement\Models\Bid;
use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 Demo Data Seeder
 * 
 * Creates demonstration data showcasing Phase 4 features:
 * - Multiple event types (Concert, Meet-the-Artist)
 * - Different acquisition modes (Direct Purchase, Bidding)
 * - Various sale window configurations
 * - Sample bids for bidding events
 */
class Phase4DemoDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('🎭 Seeding Phase 4 Demo Data...');

        // Ensure event types exist
        $this->seedEventTypes();

        // Get event types
        $concertType = EventType::where('slug', 'concert')->first();
        $meetArtistType = EventType::where('slug', 'meet-the-artist')->first();

        // Create demo customers if they don't exist (only if customers table exists)
        $customers = [];
        try {
            $customers = $this->seedDemoCustomers();
        } catch (\Exception $e) {
            $this->command->warn('  ⚠ Customers table not available, skipping customer seeding');
            $this->command->warn('  ℹ Bids will not be created for bidding events');
        }

        // Create demo events with Phase 4 features
        $this->seedDemoEvents($concertType, $meetArtistType, $customers);

        $this->command->info('✅ Phase 4 Demo Data seeded successfully!');
    }

    /**
     * Seed event types
     */
    protected function seedEventTypes(): void
    {
        $eventTypes = [
            [
                'name' => 'Concert',
                'slug' => 'concert',
                'description' => 'Live music performance with artists and bands',
                'is_active' => true,
            ],
            [
                'name' => 'Meet-the-Artist',
                'slug' => 'meet-the-artist',
                'description' => 'Exclusive meet and greet session with artists',
                'is_active' => true,
            ],
        ];

        foreach ($eventTypes as $typeData) {
            EventType::firstOrCreate(
                ['slug' => $typeData['slug']],
                $typeData
            );
        }

        $this->command->info('  ✓ Event types seeded');
    }

    /**
     * Seed demo customers
     */
    protected function seedDemoCustomers(): array
    {
        $customers = [];

        $customerData = [
            ['name' => 'John Doe', 'email' => 'john.doe@example.com', 'phone' => '+1234567890'],
            ['name' => 'Jane Smith', 'email' => 'jane.smith@example.com', 'phone' => '+1234567891'],
            ['name' => 'Mike Johnson', 'email' => 'mike.johnson@example.com', 'phone' => '+1234567892'],
            ['name' => 'Sarah Williams', 'email' => 'sarah.williams@example.com', 'phone' => '+1234567893'],
            ['name' => 'David Brown', 'email' => 'david.brown@example.com', 'phone' => '+1234567894'],
        ];

        foreach ($customerData as $data) {
            $customer = Customer::firstOrCreate(
                ['email' => $data['email']],
                $data
            );
            $customers[] = $customer;
        }

        $this->command->info('  ✓ Demo customers seeded');

        return $customers;
    }

    /**
     * Seed demo events with Phase 4 features
     */
    protected function seedDemoEvents($concertType, $meetArtistType, array $customers): void
    {
        $now = Carbon::now();

        $events = [
            // Event 1: Concert with Direct Purchase - Sale Window Open
            [
                'title' => 'Summer Rock Festival 2026',
                'description' => 'The biggest rock festival of the year featuring top international bands. Experience three days of non-stop music, food, and entertainment.',
                'event_date' => $now->copy()->addDays(45)->setTime(18, 0),
                'venue' => 'Central Park Amphitheater',
                'total_capacity' => 5000,
                'available_capacity' => 4250,
                'status' => 'published',
                'event_type_id' => $concertType->id,
                'ticket_acquisition_mode' => Event::MODE_BUY,
                'sale_start_date' => $now->copy()->subDays(7),
                'sale_end_date' => $now->copy()->addDays(40),
                'terms_conditions' => 'No refunds. Age 18+. Valid ID required.',
            ],

            // Event 2: Meet-the-Artist with Bidding - Sale Window Open
            [
                'title' => 'Exclusive Meet & Greet with Taylor Swift',
                'description' => 'A once-in-a-lifetime opportunity to meet Taylor Swift in person. Limited spots available through our bidding system. Includes photo opportunity and autograph session.',
                'event_date' => $now->copy()->addDays(30)->setTime(15, 0),
                'venue' => 'Grand Hotel Ballroom',
                'total_capacity' => 50,
                'available_capacity' => 50,
                'status' => 'published',
                'event_type_id' => $meetArtistType->id,
                'ticket_acquisition_mode' => Event::MODE_BID,
                'sale_start_date' => $now->copy()->subDays(3),
                'sale_end_date' => $now->copy()->addDays(25),
                'terms_conditions' => 'Highest bidders will be selected. Payment required within 24 hours of bid acceptance.',
            ],

            // Event 3: Concert with Direct Purchase - Sale Not Started
            [
                'title' => 'Jazz Night Under the Stars',
                'description' => 'An elegant evening of smooth jazz featuring renowned artists. Enjoy world-class performances in an intimate outdoor setting.',
                'event_date' => $now->copy()->addDays(60)->setTime(20, 0),
                'venue' => 'Riverside Jazz Club',
                'total_capacity' => 300,
                'available_capacity' => 300,
                'status' => 'published',
                'event_type_id' => $concertType->id,
                'ticket_acquisition_mode' => Event::MODE_BUY,
                'sale_start_date' => $now->copy()->addDays(5),
                'sale_end_date' => $now->copy()->addDays(55),
                'terms_conditions' => 'Smart casual dress code. No outside food or beverages.',
            ],

            // Event 4: Concert with Bidding - Sale Window Open
            [
                'title' => 'VIP Front Row Experience - Ed Sheeran',
                'description' => 'Bid for exclusive front-row seats at Ed Sheeran\'s concert. Includes backstage pass and meet & greet opportunity.',
                'event_date' => $now->copy()->addDays(50)->setTime(19, 30),
                'venue' => 'Madison Square Garden',
                'total_capacity' => 100,
                'available_capacity' => 100,
                'status' => 'published',
                'event_type_id' => $concertType->id,
                'ticket_acquisition_mode' => Event::MODE_BID,
                'sale_start_date' => $now->copy()->subDays(2),
                'sale_end_date' => $now->copy()->addDays(45),
                'terms_conditions' => 'Minimum bid $500. Winners notified 48 hours before event.',
            ],

            // Event 5: Meet-the-Artist with Direct Purchase - Sale Window Closed
            [
                'title' => 'Acoustic Session with John Mayer',
                'description' => 'Intimate acoustic performance and Q&A session with John Mayer. Limited to 100 attendees for an unforgettable experience.',
                'event_date' => $now->copy()->addDays(15)->setTime(16, 0),
                'venue' => 'Studio Theater',
                'total_capacity' => 100,
                'available_capacity' => 0,
                'status' => 'sold_out',
                'event_type_id' => $meetArtistType->id,
                'ticket_acquisition_mode' => Event::MODE_BUY,
                'sale_start_date' => $now->copy()->subDays(30),
                'sale_end_date' => $now->copy()->subDays(1),
                'terms_conditions' => 'No photography or recording allowed. Age 16+.',
            ],

            // Event 6: Concert with Direct Purchase - Almost Sold Out
            [
                'title' => 'Electronic Music Festival 2026',
                'description' => 'The ultimate EDM experience with top DJs from around the world. Dance the night away with incredible light shows and sound systems.',
                'event_date' => $now->copy()->addDays(35)->setTime(21, 0),
                'venue' => 'Convention Center Arena',
                'total_capacity' => 8000,
                'available_capacity' => 150,
                'status' => 'published',
                'event_type_id' => $concertType->id,
                'ticket_acquisition_mode' => Event::MODE_BUY,
                'sale_start_date' => $now->copy()->subDays(14),
                'sale_end_date' => $now->copy()->addDays(30),
                'terms_conditions' => 'Age 21+. Valid ID required. No re-entry.',
            ],
        ];

        foreach ($events as $eventData) {
            $event = Event::create($eventData);
            $this->command->info("  ✓ Created event: {$event->title}");

            // Create bids for bidding events
            if ($event->ticket_acquisition_mode === Event::MODE_BID && $event->available_capacity > 0) {
                $this->seedBidsForEvent($event, $customers);
            }
        }
    }

    /**
     * Seed bids for a bidding event
     */
    protected function seedBidsForEvent(Event $event, array $customers): void
    {
        if (empty($customers)) {
            return; // Skip if no customers available
        }

        $bidCount = rand(5, 15);
        $minBid = 100;
        $maxBid = 1000;

        for ($i = 0; $i < $bidCount; $i++) {
            $customer = $customers[array_rand($customers)];
            $bidAmount = rand($minBid, $maxBid);
            $quantity = rand(1, 3);

            // Vary bid status
            $statuses = [Bid::STATUS_PENDING, Bid::STATUS_PENDING, Bid::STATUS_PENDING, Bid::STATUS_ACCEPTED, Bid::STATUS_REJECTED];
            $status = $statuses[array_rand($statuses)];

            Bid::create([
                'event_id' => $event->id,
                'customer_id' => $customer->id,
                'bid_amount' => $bidAmount,
                'quantity' => $quantity,
                'status' => $status,
                'created_at' => Carbon::now()->subDays(rand(1, 10)),
            ]);
        }

        $this->command->info("    → Created {$bidCount} bids for {$event->title}");
    }
}
