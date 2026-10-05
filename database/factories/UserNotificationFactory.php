<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserNotificationFactory extends Factory
{
    protected $model = UserNotification::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => fake()->randomElement(['billing', 'subscription', 'system', 'gallery', 'dunning']),
            'title' => fake()->sentence(4),
            'body' => fake()->sentence(10),
            'action_url' => '/billing',
            'action_label' => 'View billing',
            'read_at' => null,
        ];
    }

    public function read(): static
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => now(),
        ]);
    }

    public function withoutAction(): static
    {
        return $this->state(fn (array $attributes) => [
            'action_url' => null,
            'action_label' => null,
        ]);
    }
}
