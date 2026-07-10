<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Invoice>
 */
class InvoiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'              => User::factory(),
            'transaction_id'       => Transaction::factory(),
            'invoice_number'       => 'INV-' . now()->year . '-' . str_pad((string) fake()->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'amount'               => fake()->randomElement([29.00, 99.00]),
            'tax_amount'           => 0.00,
            'tax_rate'             => 0.00,
            'currency'             => 'USD',
            'plan'                 => fake()->randomElement(['pro', 'studio']),
            'customer_name'        => fake()->name(),
            'customer_email'       => fake()->safeEmail(),
            'billing_address'      => null,
            'customer_vat_number'  => null,
            'supplier_vat_number'  => null,
            'tax_country_code'     => null,
            'reverse_charge'       => false,
            'pdf_path'             => null,
            'issued_at'            => now(),
        ];
    }
}
