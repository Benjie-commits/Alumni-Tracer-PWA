<?php

namespace Tests\Feature;

use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\EmploymentRecord;
use App\Models\User;
use App\Services\VerificationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private VerificationService $service;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->service = app(VerificationService::class);
        $this->staff = User::factory()->registrar()->create();
    }

    private function pending(array $attributes = []): AlumniProfile
    {
        $user = User::factory()->alumnus()->create();

        return AlumniProfile::factory()->pending()->create($attributes + [
            'user_id' => $user->id,
            'student_number' => null,
            'declared_student_number' => 'SU/2020/050',
            'last_name' => 'Achieng',
        ]);
    }

    public function test_approving_a_pending_profile_verifies_it_and_records_who(): void
    {
        $profile = $this->pending();

        $this->service->approve($profile, $this->staff);

        $profile->refresh();
        $this->assertSame(VerificationStatus::Verified, $profile->verification_status);
        $this->assertSame($this->staff->id, $profile->verified_by);
        $this->assertNotNull($profile->verified_at);
    }

    public function test_rejecting_a_pending_profile_records_the_decision(): void
    {
        $profile = $this->pending();

        $this->service->reject($profile, $this->staff);

        $this->assertSame(VerificationStatus::Rejected, $profile->fresh()->verification_status);
    }

    public function test_only_pending_profiles_can_be_reviewed(): void
    {
        $verified = AlumniProfile::factory()->create(['verification_status' => VerificationStatus::Verified]);

        $this->expectException(ValidationException::class);

        $this->service->approve($verified, $this->staff);
    }

    public function test_linking_moves_the_account_and_its_data_onto_the_registrar_record(): void
    {
        $pending = $this->pending(['city' => 'Soroti', 'phone' => '+256700000001']);
        $userId = $pending->user_id;
        $job = EmploymentRecord::factory()->create(['alumni_profile_id' => $pending->id]);
        $record = AlumniProfile::factory()->create(['student_number' => 'SU/2020/050', 'last_name' => 'Achieng-Okello', 'city' => null]);

        $linked = $this->service->linkToRecord($pending, $record, $this->staff);

        $this->assertSame($record->id, $linked->id);
        $this->assertSame($userId, $linked->user_id);
        $this->assertSame(VerificationStatus::Verified, $linked->verification_status);
        $this->assertSame('Soroti', $linked->city);
        $this->assertSame('SU/2020/050', $linked->student_number);
        $this->assertSame($record->id, $job->fresh()->alumni_profile_id, 'employment history follows the account');
        $this->assertNull(AlumniProfile::withTrashed()->find($pending->id), 'the self-declared shell is discarded');
        $this->assertSame($linked->id, User::find($userId)->alumniProfile->id);
    }

    public function test_a_record_that_already_has_an_owner_cannot_be_linked(): void
    {
        $pending = $this->pending();
        $owner = User::factory()->alumnus()->create();
        $taken = AlumniProfile::factory()->claimedBy($owner)->create();

        try {
            $this->service->linkToRecord($pending, $taken, $this->staff);
            $this->fail('Expected a validation error');
        } catch (ValidationException) {
            // expected
        }

        $this->assertNotNull($pending->fresh()->user_id, 'a failed link must leave the pending account attached');
        $this->assertSame($owner->id, $taken->fresh()->user_id);
    }

    public function test_candidates_are_unclaimed_records_sharing_the_surname_or_claimed_number(): void
    {
        $pending = $this->pending();
        $bySurname = AlumniProfile::factory()->create(['last_name' => 'Achieng', 'student_number' => 'SU/2019/001']);
        $byNumber = AlumniProfile::factory()->create(['last_name' => 'Different', 'student_number' => 'SU/2020/050']);
        AlumniProfile::factory()->create(['last_name' => 'Unrelated']);
        AlumniProfile::factory()->claimedBy(User::factory()->alumnus()->create())->create(['last_name' => 'Achieng']);

        $ids = $this->service->candidateRecords($pending)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$bySurname->id, $byNumber->id], $ids);
    }
}
