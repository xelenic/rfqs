<?php

namespace Database\Factories;

use App\Models\Rfq;
use App\Models\RfqPart;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RfqPart>
 */
class RfqPartFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rfq_id' => Rfq::factory(),
            'part_number' => 1,
            'user_id' => null,
        ];
    }
}
