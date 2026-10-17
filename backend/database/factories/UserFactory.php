<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'display_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'account_status' => User::STATUS_ACTIVE,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn () => ['account_status' => User::STATUS_PENDING]);
    }
}
