<?php

namespace Database\Factories;

use App\Models\SourcingDayFigure;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourcingDayFigure>
 */
class SourcingDayFigureFactory extends Factory
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
            'day' => fake()->date(),
            'successful_calls' => fake()->numberBetween(0, 20),
            'unanswered_calls' => fake()->numberBetween(0, 10),
            'unattended_calls' => fake()->numberBetween(0, 5),
            'expected_rfqs' => fake()->numberBetween(0, 10),
        ];
    }
}
