<?php

namespace Database\Factories;

use App\Models\Rfq;
use App\Models\RfqStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RfqStep>
 */
class RfqStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startedAt = fake()->dateTimeBetween('-1 month', '-1 day');

        return [
            'rfq_id' => Rfq::factory(),
            'part_number' => 1,
            'step' => fake()->randomElement(['sourcing', 'data_entry', 'finalize', 'gm_assistant']),
            'started_at' => $startedAt,
            'ended_at' => (clone $startedAt)->modify('+'.fake()->numberBetween(1, 48).' hours'),
        ];
    }

    /**
     * Still at the step — not ended yet.
     */
    public function open(): static
    {
        return $this->state(fn () => ['ended_at' => null]);
    }
}
