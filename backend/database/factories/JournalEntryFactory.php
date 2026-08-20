<?php

namespace Database\Factories;

use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

class JournalEntryFactory extends Factory
{
    protected $model = JournalEntry::class;

    public function definition(): array
    {
        return [
            'entry_number' => 'JE-'.date('Y').'-'.str_pad((string) (JournalEntry::count() + 1), 4, '0', STR_PAD_LEFT),
            'entry_date' => now()->toDateString(),
            'type' => 'manual',
            'reference' => $this->faker->optional()->word(),
            'description' => $this->faker->optional()->sentence(),
            'status' => 'draft',
            'lifecycle_status' => 'draft',
            'created_by' => \App\Models\User::factory()->create()->id,
            'version_number' => 1,
            'is_active_version' => true,
        ];
    }

    public function posted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'posted',
            'lifecycle_status' => 'posted',
            'posted_at' => now(),
            'posted_by' => 1,
        ]);
    }

    public function reversal(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_reversal' => true,
        ]);
    }

    public function invoice(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'invoice',
        ]);
    }

    public function payment(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'payment',
        ]);
    }
}
