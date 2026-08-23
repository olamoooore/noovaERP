<?php

namespace Database\Factories\Modules\POS\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosTerminal;

class PosSessionFactory extends Factory
{
    protected $model = PosSession::class;

    public function definition(): array
    {
        return [
            'terminal_id' => PosTerminal::factory(),
            'user_id' => User::factory(),
            'session_number' => strtoupper($this->faker->bothify('SES-####')),
            'status' => 'open',
            'opening_balance' => 0.00,
            'opened_at' => now(),
        ];
    }
}
