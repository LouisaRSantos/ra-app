<?php

namespace Database\Factories;

use App\Enums\ExtractionEngine;
use App\Enums\ExtractionStatus;
use App\Models\ExtractionRun;
use App\Models\Remittance;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ExtractionRun> */
class ExtractionRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'remittance_id' => Remittance::factory(),
            'engine' => ExtractionEngine::Claude,
            'status' => ExtractionStatus::Pending,
        ];
    }
}
