<?php

namespace Database\Factories;

use App\Models\PrivateMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrivateMessage>
 */
class PrivateMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sender_id' => User::factory(),
            'recipient_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }

    /**
     * A message its recipient has already opened.
     */
    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()]);
    }
}
