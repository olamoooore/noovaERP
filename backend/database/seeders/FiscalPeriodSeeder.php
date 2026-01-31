<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FiscalPeriod;
use Carbon\Carbon;

class FiscalPeriodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $userId = 1; // Assuming user ID 1 exists
        $currentYear = Carbon::now()->year;

        $periods = [];

        // Create monthly periods for previous year (all locked)
        for ($month = 1; $month <= 12; $month++) {
            $date = Carbon::create($currentYear - 1, $month, 1);
            $periods[] = [
                'name' => $date->format('F Y'),
                'code' => $date->format('Y-m'),
                'period_start' => $date->startOfMonth()->toDateString(),
                'period_end' => $date->copy()->endOfMonth()->toDateString(),
                'period_type' => 'monthly',
                'fiscal_year' => $currentYear - 1,
                'is_locked' => true,
                'locked_at' => Carbon::create($currentYear, 1, 15),
                'locked_by' => $userId,
                'status' => 'locked',
                'created_by' => $userId,
                'created_at' => $date,
                'updated_at' => Carbon::create($currentYear, 1, 15),
            ];
        }

        // Create monthly periods for current year
        for ($month = 1; $month <= 12; $month++) {
            $date = Carbon::create($currentYear, $month, 1);
            $now = Carbon::now();
            
            // Lock periods that are more than 1 month old
            $isLocked = $date->copy()->endOfMonth()->lt($now->copy()->subMonth());
            $status = $isLocked ? 'locked' : ($date->lte($now) ? 'open' : 'open');

            $periods[] = [
                'name' => $date->format('F Y'),
                'code' => $date->format('Y-m'),
                'period_start' => $date->startOfMonth()->toDateString(),
                'period_end' => $date->copy()->endOfMonth()->toDateString(),
                'period_type' => 'monthly',
                'fiscal_year' => $currentYear,
                'is_locked' => $isLocked,
                'locked_at' => $isLocked ? $date->copy()->endOfMonth()->addDays(15) : null,
                'locked_by' => $isLocked ? $userId : null,
                'status' => $status,
                'created_by' => $userId,
                'created_at' => $date,
                'updated_at' => $isLocked ? $date->copy()->endOfMonth()->addDays(15) : $date,
            ];
        }

        // Create quarterly periods for current year
        $quarters = [
            ['Q1', 1, 3],
            ['Q2', 4, 6],
            ['Q3', 7, 9],
            ['Q4', 10, 12],
        ];

        foreach ($quarters as [$quarter, $startMonth, $endMonth]) {
            $startDate = Carbon::create($currentYear, $startMonth, 1);
            $endDate = Carbon::create($currentYear, $endMonth, 1)->endOfMonth();
            $now = Carbon::now();
            
            $isLocked = $endDate->lt($now->copy()->subMonth());
            $status = $isLocked ? 'locked' : 'open';

            $periods[] = [
                'name' => "$quarter $currentYear",
                'code' => "$currentYear-$quarter",
                'period_start' => $startDate->toDateString(),
                'period_end' => $endDate->toDateString(),
                'period_type' => 'quarterly',
                'fiscal_year' => $currentYear,
                'is_locked' => $isLocked,
                'locked_at' => $isLocked ? $endDate->copy()->addDays(15) : null,
                'locked_by' => $isLocked ? $userId : null,
                'status' => $status,
                'created_by' => $userId,
                'created_at' => $startDate,
                'updated_at' => $isLocked ? $endDate->copy()->addDays(15) : $startDate,
            ];
        }

        // Create yearly period for current year
        $periods[] = [
            'name' => "FY $currentYear",
            'code' => "$currentYear-FY",
            'period_start' => Carbon::create($currentYear, 1, 1)->toDateString(),
            'period_end' => Carbon::create($currentYear, 12, 31)->toDateString(),
            'period_type' => 'yearly',
            'fiscal_year' => $currentYear,
            'is_locked' => false,
            'locked_at' => null,
            'locked_by' => null,
            'status' => 'open',
            'created_by' => $userId,
            'created_at' => Carbon::create($currentYear, 1, 1),
            'updated_at' => Carbon::create($currentYear, 1, 1),
        ];

        // Insert all periods
        foreach ($periods as $period) {
            FiscalPeriod::create($period);
        }

        $this->command->info('Fiscal periods seeded successfully!');
        $this->command->info('Created ' . count($periods) . ' fiscal periods');
        $this->command->info('Locked periods: ' . collect($periods)->where('is_locked', true)->count());
        $this->command->info('Open periods: ' . collect($periods)->where('is_locked', false)->count());
    }
}
