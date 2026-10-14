<?php

namespace Database\Factories;

use App\Models\Remittance;
use App\Models\RemittanceInvoice;
use App\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RemittanceInvoice> */
class RemittanceInvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'remittance_id' => Remittance::factory(),
            'sales_invoice_id' => SalesInvoice::factory(),
            'amount' => fake()->randomFloat(2, 100, 50000),
            'cwt_amount' => 0,
            'low_confidence' => false,
        ];
    }
}
