<?php

namespace Modules\HRM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HRM\Models\Attendance;
use Modules\HRM\Models\Employee;

class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'date'        => $this->faker->dateTimeThisMonth()->format('Y-m-d'),
            'check_in'    => '09:00',
            'check_out'   => '18:00',
            'notes'       => null,
        ];
    }
}
