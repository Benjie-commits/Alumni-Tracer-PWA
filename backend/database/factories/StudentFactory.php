<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_number' => fake()->unique()->numerify('2401600###'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'year_of_study' => 2,
            'email' => fake()->unique()->safeEmail(),
            'phone' => '+2567'.fake()->numerify('########'),
        ];
    }
}
