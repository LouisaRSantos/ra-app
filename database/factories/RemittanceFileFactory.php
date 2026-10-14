<?php

namespace Database\Factories;

use App\Models\Remittance;
use App\Models\RemittanceFile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<RemittanceFile> */
class RemittanceFileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'remittance_id' => Remittance::factory(),
            'position' => 0,
            'path' => 'extractions/'.Str::uuid().'-0.jpg',
            'original_name' => fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => fake()->numberBetween(50_000, 5_000_000),
            'is_public' => false,
        ];
    }

    public function public(): static
    {
        return $this->state(fn () => ['is_public' => true]);
    }
}
