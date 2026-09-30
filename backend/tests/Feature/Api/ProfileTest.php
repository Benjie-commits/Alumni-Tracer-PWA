<?php

namespace Tests\Feature\Api;

use App\Enums\EmploymentType;
use App\Models\EmploymentRecord;
use App\Models\User;

class ProfileTest extends ApiTestCase
{
    public function test_an_alumnus_can_view_their_profile(): void
    {
        [$user, $profile] = $this->alumnus(['graduation_year' => 2023]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/me/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $profile->id)
            ->assertJsonPath('data.graduation_year', 2023)
            ->assertJsonPath('data.verification_status.value', 'verified');
    }

    public function test_self_service_fields_can_be_updated_and_the_freshness_timestamp_moves(): void
    {
        [$user, $profile] = $this->alumnus();
        $this->assertNull($profile->profile_updated_at);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/me/profile', [
            'phone' => '+256701234567',
            'whatsapp_number' => '+256701234567',
            'city' => 'Kampala',
            'country' => 'Uganda',
            'employment_status' => 'employed',
            'further_study_status' => 'planned',
            'further_study_institution' => 'Makerere University',
        ])->assertOk()->assertJsonPath('data.city', 'Kampala');

        $profile->refresh();
        $this->assertSame('employed', $profile->employment_status->value);
        $this->assertNotNull($profile->profile_updated_at);
    }

    public function test_registrar_owned_fields_cannot_be_changed_by_the_alumnus(): void
    {
        [$user, $profile] = $this->alumnus(['graduation_year' => 2023, 'student_number' => 'SU/2019/100']);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/me/profile', [
            'graduation_year' => 2010,
            'student_number' => 'SU/HACKED/1',
            'first_name' => 'Someone',
            'verification_status' => 'verified',
            'city' => 'Gulu',
        ])->assertOk();

        $profile->refresh();
        $this->assertSame(2023, $profile->graduation_year);
        $this->assertSame('SU/2019/100', $profile->student_number);
        $this->assertSame('Amina', $profile->first_name);
        $this->assertSame('Gulu', $profile->city);
    }

    public function test_changing_the_contact_email_keeps_the_login_email_in_step(): void
    {
        [$user] = $this->alumnus();

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/me/profile', ['email' => 'new.address@example.com'])->assertOk();

        $this->assertSame('new.address@example.com', $user->fresh()->email);
    }

    public function test_the_email_cannot_be_taken_from_another_account(): void
    {
        [$user] = $this->alumnus();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/me/profile', ['email' => 'taken@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_invalid_enum_values_are_rejected(): void
    {
        [$user] = $this->alumnus();

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/me/profile', ['employment_status' => 'astronaut'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employment_status');
    }

    public function test_employment_records_can_be_added_listed_edited_and_removed(): void
    {
        [$user, $profile] = $this->alumnus();
        $this->actingAs($user, 'sanctum');

        $id = $this->postJson('/api/v1/me/employment-records', [
            'employer' => 'Soroti Fruit Factory',
            'job_title' => 'Quality Officer',
            'employment_type' => 'employed',
            'start_date' => '2024-03-01',
            'is_current' => true,
        ])->assertCreated()->assertJsonPath('data.employer', 'Soroti Fruit Factory')->json('data.id');

        $this->getJson('/api/v1/me/employment-records')->assertOk()->assertJsonCount(1, 'data');

        $this->putJson("/api/v1/me/employment-records/{$id}", [
            'employer' => 'Soroti Fruit Factory',
            'job_title' => 'Senior Quality Officer',
            'employment_type' => 'employed',
        ])->assertOk()->assertJsonPath('data.job_title', 'Senior Quality Officer');

        $this->deleteJson("/api/v1/me/employment-records/{$id}")->assertNoContent();
        $this->assertSame(0, $profile->employmentRecords()->count());
        $this->assertNotNull($profile->fresh()->profile_updated_at);
    }

    public function test_employment_records_belonging_to_someone_else_look_like_they_do_not_exist(): void
    {
        [$user] = $this->alumnus();
        [, $otherProfile] = $this->alumnus(['student_number' => 'SU/2020/777']);
        // second alumnus() call created another user; use their record
        $theirRecord = EmploymentRecord::factory()->create(['alumni_profile_id' => $otherProfile->id]);

        $this->actingAs($user, 'sanctum');

        $payload = ['employer' => 'Hijacked', 'employment_type' => EmploymentType::Employed->value];
        $this->putJson("/api/v1/me/employment-records/{$theirRecord->id}", $payload)->assertNotFound();
        $this->deleteJson("/api/v1/me/employment-records/{$theirRecord->id}")->assertNotFound();

        $this->assertDatabaseHas('employment_records', ['id' => $theirRecord->id, 'employer' => $theirRecord->employer]);
    }

    public function test_employment_dates_are_validated(): void
    {
        [$user] = $this->alumnus();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/me/employment-records', [
            'employer' => 'Acme',
            'employment_type' => 'employed',
            'start_date' => '2024-06-01',
            'end_date' => '2024-01-01',
        ])->assertUnprocessable()->assertJsonValidationErrors('end_date');
    }

    public function test_reference_data_is_public(): void
    {
        $this->getJson('/api/v1/reference/programmes')->assertOk()->assertJsonStructure(['data']);
        $this->getJson('/api/v1/reference/options')->assertOk()->assertJsonPath('data.employment_status.0.value', 'employed');
    }
}
