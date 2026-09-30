<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\School;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'school_id' => School::factory(),
            'name' => 'Department of '.fake()->unique()->words(2, true),
            'code' => strtoupper(fake()->lexify('D??')),
        ];
    }
}
