<?php

namespace Database\Factories;

use App\Models\Rfq;
use App\Models\RfqCommentAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RfqCommentAttachment>
 */
class RfqCommentAttachmentFactory extends Factory
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
            // A comment of its own on that RFQ, unless one's given.
            'rfq_comment_id' => fn (array $attributes) => Rfq::query()->findOrFail($attributes['rfq_id'])->comments()->create([
                'user_id' => User::factory()->create()->id,
                'body' => fake()->sentence(),
            ])->id,
            'path' => 'rfq-attachments/'.fake()->uuid().'.jpg',
            'original_name' => fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => fake()->numberBetween(10_000, 2_000_000),
        ];
    }
}
