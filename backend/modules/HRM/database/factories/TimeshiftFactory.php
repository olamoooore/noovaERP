<?php

namespace Modules\HRM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HRM\Models\Timeshift;

class TimeshiftFactory extends Factory
{
    protected $model = Timeshift::class;

    public function definition(): array
    {
        $days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];

        return [
            'name'       => $this->faker->randomElement(['Morning Shift', 'Evening Shift', 'Night Shift', 'Standard Shift']),
            'is_default' => false,
            'schedule'   => array_map(fn($day) => [
                'day'     => $day,
                'enabled' => in_array($day, ['monday','tuesday','wednesday','thursday','friday']),
                'start'   => '09:00',
                'end'     => '18:00',
            ], $days),
        ];
    }
}
