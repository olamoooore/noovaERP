<?php

namespace Modules\HRM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HRM\Models\LeaveRequest;
use Modules\HRM\Models\Employee;

class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    public function definition(): array
    {
        $start = $this->faker->dateTimeThisMonth();
        $end   = (clone $start)->modify('+2 days');

        return [
            'employee_id' => Employee::factory(),
            'start_date'  => $start->format('Y-m-d'),
            'end_date'    => $end->format('Y-m-d'),
            'leave_type'  => $this->faker->randomElement(['sick', 'vacation', 'personal', 'unpaid']),
            'status'      => 'pending',
            'message'     => $this->faker->sentence(),
        ];
    }
}
