<?php

namespace Database\Factories;

use App\Enums\EmploymentType;
use App\Models\AlumniProfile;
use App\Models\EmploymentRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmploymentRecord>
 */
class EmploymentRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'alumni_profile_id' => AlumniProfile::factory(),
            'employer' => fake()->company(),
            'job_title' => fake()->jobTitle(),
            'employment_type' => EmploymentType::Employed,
            'start_date' => fake()->dateTimeBetween('-3 years', '-1 month')->format('Y-m-d'),
            'is_current' => true,
        ];
    }
}
