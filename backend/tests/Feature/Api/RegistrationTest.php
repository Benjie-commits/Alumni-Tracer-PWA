<?php

namespace Tests\Feature\Api;

use App\Enums\RecordSource;
use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\User;

class RegistrationTest extends ApiTestCase
{
    public function test_matching_a_registrar_record_verifies_and_claims_it(): void
    {
        $record = $this->importedRecord();

        $response = $this->postJson('/api/v1/auth/register', $this->registrationPayload());

        $response->assertCreated()
            ->assertJsonPath('user.role', 'alumni')
            ->assertJsonPath('user.profile.verification_status.value', 'verified')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'profile' => ['student_number']]]);

        $record->refresh();
        $this->assertNotNull($record->user_id);
        $this->assertSame(VerificationStatus::Verified, $record->verification_status);
        $this->assertSame('amina@example.com', $record->email);
        $this->assertNotNull($record->consented_at);
        $this->assertSame(1, AlumniProfile::count(), 'a match must claim the existing record, not create a second one');
    }

    public function test_matching_ignores_case_and_extra_whitespace(): void
    {
        $this->importedRecord();

        $this->postJson('/api/v1/auth/register', $this->registrationPayload([
            'student_number' => '  su/2021/014 ',
            'last_name' => ' OKELLO ',
        ]))->assertCreated()->assertJsonPath('user.profile.verification_status.value', 'verified');
    }

    public function test_a_mismatch_creates_a_pending_claim_and_leaves_the_record_alone(): void
    {
        $record = $this->importedRecord();

        $response = $this->postJson('/api/v1/auth/register', $this->registrationPayload(['last_name' => 'Okelo']));

        $response->assertCreated()->assertJsonPath('user.profile.verification_status.value', 'pending');

        $this->assertNull($record->fresh()->user_id, 'the imported record must stay unclaimed');

        $pending = User::where('email', 'amina@example.com')->first()->alumniProfile;
        $this->assertSame(VerificationStatus::Pending, $pending->verification_status);
        $this->assertSame(RecordSource::SelfRegistered, $pending->record_source);
        $this->assertSame('SU/2021/014', $pending->declared_student_number);
        $this->assertNull($pending->student_number, 'a claim must never occupy a real student number');
    }

    public function test_an_unknown_student_number_is_accepted_as_pending_without_revealing_anything(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationPayload(['student_number' => 'XX/0000/000']))
            ->assertCreated()
            ->assertJsonPath('user.profile.verification_status.value', 'pending');
    }

    public function test_an_already_claimed_record_cannot_be_registered_again(): void
    {
        $this->importedRecord();
        $this->postJson('/api/v1/auth/register', $this->registrationPayload())->assertCreated();

        $this->postJson('/api/v1/auth/register', $this->registrationPayload(['email' => 'someone.else@example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_number');

        $this->assertDatabaseMissing('users', ['email' => 'someone.else@example.com']);
    }

    public function test_the_email_must_be_unique(): void
    {
        User::factory()->alumnus()->create(['email' => 'amina@example.com']);

        $this->postJson('/api/v1/auth/register', $this->registrationPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_consent_is_required(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationPayload(['consent' => false]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('consent');
    }

    public function test_weak_passwords_and_bad_phone_numbers_are_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', $this->registrationPayload(['password' => 'short', 'phone' => 'call me']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password', 'phone']);
    }

    public function test_a_failed_registration_creates_nothing(): void
    {
        $this->importedRecord();

        $this->postJson('/api/v1/auth/register', $this->registrationPayload(['graduation_year' => 1800]))->assertUnprocessable();

        $this->assertSame(0, User::count());
        $this->assertSame(1, AlumniProfile::count());
    }
}
