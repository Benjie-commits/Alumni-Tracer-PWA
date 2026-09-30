<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Programme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Programme>
 */
class ProgrammeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'name' => 'Bachelor of '.fake()->unique()->words(2, true),
            'code' => strtoupper(fake()->unique()->lexify('B???')),
            'level' => 'bachelor',
        ];
    }
}
