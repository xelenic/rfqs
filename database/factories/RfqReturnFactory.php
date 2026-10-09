<?php

namespace Database\Factories;

use App\Models\Rfq;
use App\Models\RfqReturn;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RfqReturn>
 */
class RfqReturnFactory extends Factory
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
            'batch' => (string) Str::uuid(),
            'part_number' => 1,
            'from_stage' => 'head_of_bd_review',
            'target_stage' => 'sourcing',
            'part_before' => [],
            'rfq_before' => [],
        ];
    }
}
