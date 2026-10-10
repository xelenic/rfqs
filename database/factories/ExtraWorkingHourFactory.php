<?php

namespace Database\Factories;

use App\Models\ExtraWorkingHour;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExtraWorkingHour>
 */
class ExtraWorkingHourFactory extends Factory
{
    /**
     * Define the model's default state: weekday evenings, 19:00–20:00.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'start' => '19:00',
            'end' => '20:00',
            'starts_on' => '2026-01-01',
        ];
    }
}
