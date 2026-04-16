<?php

namespace Modules\HRM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HRM\Models\Contract;
use Modules\HRM\Models\Employee;

class ContractFactory extends Factory
{
    protected $model = Contract::class;

    public function definition(): array
    {
        return [
            'employee_id'         => Employee::factory(),
            'job_title'           => $this->faker->jobTitle(),
            'employment_type'     => $this->faker->randomElement(['full_time', 'part_time', 'contract', 'intern']),
            'status'              => 'draft',
            'base_monthly_salary' => $this->faker->numberBetween(2000, 10000),
            'overtime_eligible'   => true,
            'fixed_working_days'  => 26,
            'paid_leaves'         => 14,
            'start_date'          => now()->toDateString(),
        ];
    }
}
