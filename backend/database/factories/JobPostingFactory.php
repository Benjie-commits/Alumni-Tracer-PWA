<?php

namespace Database\Factories;

use App\Enums\JobType;
use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPosting>
 */
class JobPostingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => fake()->jobTitle(),
            'organisation' => fake()->company(),
            'type' => JobType::Job,
            'location' => 'Soroti',
            'description' => fake()->paragraph(),
            'how_to_apply' => 'careers@example.com',
            'closes_on' => now()->addMonth()->toDateString(),
            'published_at' => now()->subDay(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['published_at' => null]);
    }
}
