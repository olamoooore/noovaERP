<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EventManagementTestSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // When called via `tenants:seed`, the tenancy framework already sets
        // the correct DB search_path for each tenant — no manual override needed.

        // Ensure event_types has at least one row
        if (DB::table('event_types')->count() === 0) {
            DB::table('event_types')->insert([
                ['name' => 'Concert', 'slug' => 'concert', 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Conference', 'slug' => 'conference', 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Exhibition', 'slug' => 'exhibition', 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        $typeId = DB::table('event_types')->value('id');

        if (DB::table('events')->count() > 0) {
            $this->command->info('Events already exist, skipping.');
            return;
        }

        $events = [
            [
                'title'                  => 'K-Pop Night Vol. 1',
                'description'            => 'An amazing night of K-Pop performances featuring top artists.',
                'venue'                  => 'Manila Arena, Philippines',
                'event_date'             => $now->copy()->addDays(30)->toDateTimeString(),
                'start_date'             => $now->copy()->addDays(30)->toDateTimeString(),
                'end_date'               => $now->copy()->addDays(30)->addHours(4)->toDateTimeString(),
                'total_capacity'         => 5000,
                'available_capacity'     => 5000,
                'status'                 => 'published',
                'event_type_id'          => $typeId,
                'ticket_acquisition_mode'=> 'buy',
                'sale_start_date'        => $now->copy()->addDay()->toDateTimeString(),
                'sale_end_date'          => $now->copy()->addDays(28)->toDateTimeString(),
                'created_at'             => $now,
                'updated_at'             => $now,
            ],
            [
                'title'                  => 'NoovaERP Music Festival 2026',
                'description'            => 'Annual music festival with local and international artists.',
                'venue'                  => 'SM Mall of Asia Arena, Pasay City',
                'event_date'             => $now->copy()->addDays(60)->toDateTimeString(),
                'start_date'             => $now->copy()->addDays(60)->toDateTimeString(),
                'end_date'               => $now->copy()->addDays(62)->toDateTimeString(),
                'total_capacity'         => 15000,
                'available_capacity'     => 15000,
                'status'                 => 'published',
                'event_type_id'          => $typeId,
                'ticket_acquisition_mode'=> 'buy',
                'sale_start_date'        => $now->copy()->addDays(5)->toDateTimeString(),
                'sale_end_date'          => $now->copy()->addDays(55)->toDateTimeString(),
                'created_at'             => $now,
                'updated_at'             => $now,
            ],
            [
                'title'                  => 'Exclusive Bid Concert – Premium Seats',
                'description'            => 'Exclusive concert with bid-only premium seats.',
                'venue'                  => 'Solaire Resort & Casino, Entertainment City',
                'event_date'             => $now->copy()->addDays(45)->toDateTimeString(),
                'start_date'             => $now->copy()->addDays(45)->toDateTimeString(),
                'end_date'               => $now->copy()->addDays(45)->addHours(3)->toDateTimeString(),
                'total_capacity'         => 800,
                'available_capacity'     => 800,
                'status'                 => 'published',
                'event_type_id'          => $typeId,
                'ticket_acquisition_mode'=> 'bid',
                'sale_start_date'        => $now->copy()->addDays(2)->toDateTimeString(),
                'sale_end_date'          => $now->copy()->addDays(40)->toDateTimeString(),
                'created_at'             => $now,
                'updated_at'             => $now,
            ],
            [
                'title'                  => 'Staff Training Workshop',
                'description'            => 'Internal staff training and coordination event.',
                'venue'                  => 'NoovaERP HQ Conference Room',
                'event_date'             => $now->copy()->addDays(10)->toDateTimeString(),
                'start_date'             => $now->copy()->addDays(10)->toDateTimeString(),
                'end_date'               => $now->copy()->addDays(10)->addHours(8)->toDateTimeString(),
                'total_capacity'         => 50,
                'available_capacity'     => 50,
                'status'                 => 'published',
                'event_type_id'          => $typeId,
                'ticket_acquisition_mode'=> 'buy',
                'sale_start_date'        => $now->toDateTimeString(),
                'sale_end_date'          => $now->copy()->addDays(8)->toDateTimeString(),
                'created_at'             => $now,
                'updated_at'             => $now,
            ],
        ];

        DB::table('events')->insert($events);
        $this->command->info('Seeded ' . count($events) . ' test events successfully.');
    }
}
