<?php

namespace Database\Factories;

use App\Models\EventPosting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventPosting>
 */
class EventPostingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => 'Alumni '.fake()->words(2, true),
            'description' => fake()->paragraph(),
            'starts_at' => now()->addWeeks(2),
            'ends_at' => now()->addWeeks(2)->addHours(3),
            'venue' => 'Main Hall, Soroti University',
            'published_at' => now()->subDay(),
        ];
    }
}
