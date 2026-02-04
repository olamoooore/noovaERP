<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\OCRBookkeeping\Models\OcrDocument;
use Illuminate\Support\Str;

class OcrDocumentFactory extends Factory
{
    protected $model = OcrDocument::class;

    public function definition(): array
    {
        return [
            'uuid' => Str::uuid(),
            'file_path' => '2024/12/26/' . $this->faker->uuid . '.jpg',
            'file_name' => $this->faker->word . '.jpg',
            'file_size' => $this->faker->numberBetween(100000, 5000000),
            'mime_type' => 'image/jpeg',
            'status' => 'PENDING',
            'created_by' => 1,
        ];
    }

    public function needsReview(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'NEEDS_REVIEW',
            'extracted_at' => now(),
        ]);
    }

    public function posted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'POSTED',
            'posted_at' => now(),
            'posted_by' => 1,
        ]);
    }
}
