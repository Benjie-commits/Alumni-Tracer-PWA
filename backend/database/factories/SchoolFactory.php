<?php

namespace Database\Factories;

use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<School>
 */
class SchoolFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'School of '.fake()->unique()->words(2, true),
            'code' => strtoupper(fake()->unique()->lexify('S??')),
        ];
    }
}
