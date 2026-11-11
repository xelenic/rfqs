<?php

namespace Database\Factories;

use App\Models\SourcingExpectedRfq;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourcingExpectedRfq>
 */
class SourcingExpectedRfqFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'expected_rfqs' => fake()->numberBetween(0, 20),
            'starts_on' => fake()->date(),
            'set_by' => null,
        ];
    }
}
