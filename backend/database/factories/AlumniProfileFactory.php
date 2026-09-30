<?php

namespace Database\Factories;

use App\Enums\RecordSource;
use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\Programme;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Default state is an unclaimed Registrar-imported record.
 *
 * @extends Factory<AlumniProfile>
 */
class AlumniProfileFactory extends Factory
{
    public function definition(): array
    {
        $year = fake()->numberBetween(2018, 2025);

        return [
            'user_id' => null,
            'student_number' => fake()->unique()->numerify('SU/####/###'),
            'programme_id' => Programme::factory(),
            'graduation_year' => $year,
            'graduation_date' => "{$year}-".fake()->randomElement(['06', '12']).'-15',
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'gender' => fake()->randomElement(['female', 'male']),
            'record_source' => RecordSource::RegistrarImport,
            'verification_status' => VerificationStatus::Unclaimed,
        ];
    }

    /** A record claimed by a registered alumnus. */
    public function claimedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
            'verification_status' => VerificationStatus::Verified,
            'claimed_at' => now(),
            'verified_at' => now(),
            'email' => $user->email,
        ]);
    }

    /** A self-declared alumnus awaiting Registrar review. */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'record_source' => RecordSource::SelfRegistered,
            'verification_status' => VerificationStatus::Pending,
            'claimed_at' => now(),
        ]);
    }
}
