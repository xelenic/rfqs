<?php

namespace Database\Factories;

use App\Models\Rfq;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rfq>
 */
class RfqFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'wc_number' => 'WC'.fake()->unique()->numerify('####'),
            'rfq_number' => 'RFQ'.fake()->unique()->numerify('####'),
            'priority_level' => fake()->randomElement(Rfq::PRIORITIES),
            'status' => 'Pending',
            'subject' => fake()->sentence(4),
            'description' => fake()->paragraph(),
        ];
    }
}
