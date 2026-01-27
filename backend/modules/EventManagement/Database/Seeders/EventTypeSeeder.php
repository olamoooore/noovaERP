<?php

namespace Modules\EventManagement\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EventTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $eventTypes = [
            [
                'name' => 'Concert',
                'slug' => 'concert',
                'description' => 'Live music performance',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Meet-the-Artist',
                'slug' => 'meet-the-artist',
                'description' => 'Personal meet and greet with artists',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        // Use insertOrIgnore to make seeder idempotent
        foreach ($eventTypes as $eventType) {
            DB::table('event_types')->updateOrInsert(
                ['slug' => $eventType['slug']],
                $eventType
            );
        }
    }
}
