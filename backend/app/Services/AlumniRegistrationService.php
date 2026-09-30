<?php

namespace App\Services;

use App\Enums\RecordSource;
use App\Enums\RoleSlug;
use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * FR-2: an alumnus registers and is verified against Registrar records.
 *
 * Student number + surname + graduation year must all match an unclaimed imported record for the
 * account to be verified straight away. Anything else is accepted as a self-declared claim in
 * 'pending' status for the Registrar to review, so alumni missing from the spreadsheets are not
 * locked out, and a failed match reveals nothing about which records exist.
 */
class AlumniRegistrationService
{
    /**
     * @param  array{
     *     student_number: string,
     *     last_name: string,
     *     first_name: string,
     *     graduation_year: int|string,
     *     email: string,
     *     phone?: ?string,
     *     password: string,
     *     programme_id?: int|string|null,
     * }  $data
     */
    public function register(array $data): AlumniProfile
    {
        $studentNumber = $this->normaliseStudentNumber($data['student_number']);
        $lastName = $this->squish($data['last_name']);
        $year = (int) $data['graduation_year'];

        return DB::transaction(function () use ($data, $studentNumber, $lastName, $year) {
            $record = AlumniProfile::query()
                ->where('student_number', $studentNumber)
                ->where('last_name', $lastName)
                ->where('graduation_year', $year)
                ->lockForUpdate()
                ->first();

            if ($record?->isClaimed()) {
                throw ValidationException::withMessages([
                    'student_number' => 'This record is already registered. Sign in instead, or contact the Registrar\'s office if this was not you.',
                ]);
            }

            return $record !== null
                ? $this->claim($record, $data)
                : $this->declare($data, $studentNumber, $lastName, $year);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function claim(AlumniProfile $record, array $data): AlumniProfile
    {
        $user = $this->createUser($record->full_name, $data);

        $record->fill([
            'user_id' => $user->id,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? $record->phone,
            'verification_status' => VerificationStatus::Verified,
            'claimed_at' => now(),
            'verified_at' => now(),
            'consented_at' => now(),
            'profile_updated_at' => now(),
        ])->save();

        return $record;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function declare(array $data, string $studentNumber, string $lastName, int $year): AlumniProfile
    {
        $firstName = $this->squish($data['first_name']);
        $user = $this->createUser("{$firstName} {$lastName}", $data);

        return AlumniProfile::query()->create([
            'user_id' => $user->id,
            'declared_student_number' => $studentNumber,
            'programme_id' => $data['programme_id'] ?? null,
            'graduation_year' => $year,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'record_source' => RecordSource::SelfRegistered,
            'verification_status' => VerificationStatus::Pending,
            'claimed_at' => now(),
            'consented_at' => now(),
            'profile_updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createUser(string $name, array $data): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'role_id' => Role::idFor(RoleSlug::Alumni),
        ]);
    }

    public function normaliseStudentNumber(string $value): string
    {
        return strtoupper($this->squish($value));
    }

    private function squish(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
