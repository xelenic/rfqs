<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\AttendanceSheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attendance_sheet_id' => AttendanceSheet::factory(),
            'user_id' => User::factory(),
            'status' => Attendance::PRESENT,
        ];
    }

    /**
     * Off that day.
     */
    public function absent(string $reason = 'Sick leave'): static
    {
        return $this->state(fn () => ['status' => Attendance::ABSENT, 'reason' => $reason]);
    }
}
