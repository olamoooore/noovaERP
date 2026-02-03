<?php

namespace Modules\PaymentGateway\Database\Seeders;

use Illuminate\Database\Seeder;

class PaymentGatewayDatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            PaymentGatewaySeeder::class,
            // Add more seeders here as needed
            // PaymentSampleDataSeeder::class,
        ]);
    }
}
