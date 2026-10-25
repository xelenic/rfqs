<?php

namespace Database\Factories;

use App\Models\MessageGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageGroup>
 */
class MessageGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true).' team',
            'description' => fake()->optional()->sentence(),
        ];
    }
}
