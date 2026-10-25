<?php

namespace Database\Factories;

use App\Models\GroupMessage;
use App\Models\MessageGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GroupMessage>
 */
class GroupMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_group_id' => MessageGroup::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
