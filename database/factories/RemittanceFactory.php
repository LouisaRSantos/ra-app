<?php

namespace Database\Factories;

use App\Enums\ExtractionStatus;
use App\Models\Remittance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Remittance> */
class RemittanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => ExtractionStatus::Pending,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => ExtractionStatus::Completed,
            'payment_reference_number' => (string) fake()->numerify('##########'),
            'cr_number' => fake()->numerify('#####'),
            'deposit_date' => fake()->date(),
            'total_amount' => fake()->randomFloat(2, 1000, 90000),
            'total_cwt_amount' => 0,
            'low_confidence_fields' => [],
        ]);
    }

    public function confirmedBy(User $user): static
    {
        return $this->state(fn () => ['confirmed_by' => $user->id, 'confirmed_at' => now()]);
    }
}
