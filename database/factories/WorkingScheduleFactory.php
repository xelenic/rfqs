<?php

namespace Database\Factories;

use App\Models\Setting;
use App\Models\User;
use App\Models\WorkingSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkingSchedule>
 */
class WorkingScheduleFactory extends Factory
{
    /**
     * Define the model's default state: someone's own week, starting and
     * finishing an hour early — Mon–Fri 07:30–16:00, lunch 12:30–13:30.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'week' => collect(Setting::weekFrom([]))->map(fn (array $day) => array_merge($day, ['start' => '07:30', 'end' => '16:00']))->all(),
            'starts_on' => '2026-01-01',
        ];
    }
}
