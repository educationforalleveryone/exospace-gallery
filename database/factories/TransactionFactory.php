<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'        => User::factory(),
            'invoice_id'     => 'INV-' . fake()->unique()->numberBetween(100000, 999999),
            'sale_id'        => 'SALE-' . fake()->numberBetween(100000, 999999),
            'product_id'     => (string) fake()->numberBetween(1000, 9999),
            'plan'           => fake()->randomElement(['pro', 'studio']),
            'amount'         => fake()->randomElement([29.00, 99.00]),
            'currency'       => 'USD',
            'customer_email' => fake()->safeEmail(),
            'customer_name'  => fake()->name(),
            'status'         => 'completed',
        ];
    }
}
