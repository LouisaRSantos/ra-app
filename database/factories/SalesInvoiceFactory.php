<?php

namespace Database\Factories;

use App\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SalesInvoice> */
class SalesInvoiceFactory extends Factory
{
    public function definition(): array
    {
        return ['si_number' => 'MADBSI'.fake()->unique()->numerify('##########')];
    }
}
