<?php

namespace Tests\Feature\Admin;

use App\Enums\VerificationStatus;
use App\Livewire\Admin\AlumniDetail;
use App\Livewire\Admin\ImportAlumni;
use App\Livewire\Admin\StaffUsers;
use App\Livewire\Admin\VerificationQueue;
use App\Models\AlumniProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

class RecordManagementTest extends AdminTestCase
{
    public function test_a_registrar_can_edit_a_record_and_the_freshness_date_is_untouched(): void
    {
        $profile = $this->profile(['first_name' => 'Amina', 'last_name' => 'Okelo', 'profile_updated_at' => null]);

        Livewire::actingAs($this->registrar())->test(AlumniDetail::class, ['profile' => $profile])
            ->set('form.last_name', 'Okello')
            ->set('form.student_number', ' su/2021/014 ')
            ->set('form.phone', '+256700111222')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('saved', true);

        $profile->refresh();
        $this->assertSame('Okello', $profile->last_name);
        $this->assertSame('SU/2021/014', $profile->student_number);
        $this->assertNull($profile->profile_updated_at, 'staff edits must not look like alumnus confirmation');
    }

    public function test_edits_are_validated_including_student_number_uniqueness(): void
    {
        $this->profile(['student_number' => 'SU/2021/001']);
        $profile = $this->profile(['student_number' => 'SU/2021/002']);

        Livewire::actingAs($this->registrar())->test(AlumniDetail::class, ['profile' => $profile])
            ->set('form.student_number', 'SU/2021/001')
            ->set('form.first_name', '')
            ->set('form.phone', 'not a phone')
            ->call('save')
            ->assertHasErrors(['form.student_number', 'form.first_name', 'form.phone']);
    }

    public function test_a_record_can_keep_its_own_student_number(): void
    {
        $profile = $this->profile(['student_number' => 'SU/2021/002']);

        Livewire::actingAs($this->registrar())->test(AlumniDetail::class, ['profile' => $profile])
            ->set('form.city', 'Soroti')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Soroti', $profile->fresh()->city);
    }

    public function test_qa_viewers_cannot_save_and_never_receive_personal_fields(): void
    {
        $profile = $this->profile(['last_name' => 'Okello', 'email' => 'private@example.com', 'phone' => '+256700555111', 'date_of_birth' => '1999-04-02']);

        $component = Livewire::actingAs($this->qaViewer())->test(AlumniDetail::class, ['profile' => $profile])
            ->assertSee('Okello')
            ->assertDontSee('private@example.com')
            ->assertDontSee('+256700555111')
            ->assertDontSee('1999-04-02');

        foreach (['email', 'phone', 'whatsapp_number', 'date_of_birth'] as $field) {
            $this->assertArrayNotHasKey($field, $component->get('form'), "{$field} must not be in the client payload");
        }

        $component->set('form.last_name', 'Hacked')->call('save')->assertForbidden();
        $this->assertSame('Okello', $profile->fresh()->last_name);
    }

    public function test_approving_a_pending_claim(): void
    {
        $claim = $this->pendingClaim();

        Livewire::actingAs($registrar = $this->registrar())->test(VerificationQueue::class)
            ->assertSee($claim->full_name)
            ->call('approve', $claim->id)
            ->assertSee('Approved');

        $this->assertSame(VerificationStatus::Verified, $claim->fresh()->verification_status);
        $this->assertSame($registrar->id, $claim->fresh()->verified_by);
    }

    public function test_rejecting_a_pending_claim(): void
    {
        $claim = $this->pendingClaim();

        Livewire::actingAs($this->registrar())->test(VerificationQueue::class)->call('reject', $claim->id);

        $this->assertSame(VerificationStatus::Rejected, $claim->fresh()->verification_status);
    }

    public function test_linking_a_claim_to_a_registrar_record(): void
    {
        $claim = $this->pendingClaim(['last_name' => 'Achieng']);
        $record = $this->profile(['student_number' => 'SU/2020/050', 'last_name' => 'Achieng']);
        $userId = $claim->user_id;

        Livewire::actingAs($this->registrar())->test(VerificationQueue::class)
            ->assertSee('SU/2020/050')
            ->call('link', $claim->id, $record->id)
            ->assertSee('Linked');

        $this->assertSame($userId, $record->fresh()->user_id);
        $this->assertNull(AlumniProfile::withTrashed()->find($claim->id));
    }

    public function test_only_pending_claims_can_be_actioned_from_the_queue(): void
    {
        $verified = $this->profile(['verification_status' => VerificationStatus::Verified]);

        Livewire::actingAs($this->registrar())->test(VerificationQueue::class)->call('reject', $verified->id)->assertNotFound();

        $this->assertSame(VerificationStatus::Verified, $verified->fresh()->verification_status);
    }

    public function test_qa_viewers_cannot_open_or_drive_the_verification_queue(): void
    {
        $claim = $this->pendingClaim();

        Livewire::actingAs($this->qaViewer())->test(VerificationQueue::class)->assertForbidden();

        $this->assertSame(VerificationStatus::Pending, $claim->fresh()->verification_status);
    }

    public function test_import_preview_saves_nothing_and_commit_saves(): void
    {
        $csv = UploadedFile::fake()->createWithContent('graduates.csv',
            "Student number,First name,Surname,Programme,School,Graduation year\nSU/2021/001,Amina,Okello,BSc Biology,School of Science,2024\n");

        $page = Livewire::actingAs($this->registrar())->test(ImportAlumni::class)
            ->set('file', $csv)
            ->call('preview')
            ->assertHasNoErrors()
            ->assertSet('report.dry_run', true)
            ->assertSet('report.created', 1)
            ->assertSee('This is a preview');

        $this->assertSame(0, AlumniProfile::count());

        $page->call('commit')->assertSet('report.dry_run', false)->assertSet('report.created', 1);

        $this->assertSame(1, AlumniProfile::where('student_number', 'SU/2021/001')->count());
    }

    public function test_import_rejects_files_without_the_required_columns(): void
    {
        $csv = UploadedFile::fake()->createWithContent('bad.csv', "Name,Year\nAmina,2024\n");

        Livewire::actingAs($this->registrar())->test(ImportAlumni::class)
            ->set('file', $csv)
            ->call('preview')
            ->assertHasErrors('file');
    }

    public function test_import_is_closed_to_qa_viewers(): void
    {
        Livewire::actingAs($this->qaViewer())->test(ImportAlumni::class)->assertForbidden();
    }

    public function test_ict_admin_can_add_staff_but_not_with_a_weak_password_or_the_alumni_role(): void
    {
        $page = Livewire::actingAs($this->ictAdmin())->test(StaffUsers::class);

        $page->set('name', 'Grace Akello')->set('email', 'grace@example.com')->set('role', 'registrar')->set('password', 'short')
            ->call('create')->assertHasErrors('password');

        $page->set('password', 'a-long-enough-passphrase')->set('role', 'alumni')
            ->call('create')->assertHasErrors('role');

        $page->set('role', 'registrar')->call('create')->assertHasNoErrors();

        $created = User::where('email', 'grace@example.com')->first();
        $this->assertNotNull($created);
        $this->assertSame('registrar', $created->role->slug);
    }

    public function test_the_last_active_ict_admin_and_yourself_cannot_be_deactivated(): void
    {
        $ict = $this->ictAdmin();
        $registrar = $this->registrar();

        $page = Livewire::actingAs($ict)->test(StaffUsers::class);

        $page->call('toggleActive', $ict->id)->assertHasErrors('toggle');
        $this->assertTrue($ict->fresh()->is_active);

        $page->call('toggleActive', $registrar->id)->assertHasNoErrors();
        $this->assertFalse($registrar->fresh()->is_active);
    }

    public function test_deactivating_staff_revokes_their_tokens_and_a_password_reset_works(): void
    {
        $ict = $this->ictAdmin();
        $other = $this->registrar();
        $other->createToken('x');

        $page = Livewire::actingAs($ict)->test(StaffUsers::class);
        $page->call('toggleActive', $other->id);
        $this->assertSame(0, $other->tokens()->count());

        $page->call('startReset', $other->id)->set('newPassword', 'brand-new-passphrase')->call('savePassword')->assertHasNoErrors();
        $this->assertTrue(Hash::check('brand-new-passphrase', $other->fresh()->password));
    }

    public function test_registrars_cannot_drive_staff_management(): void
    {
        Livewire::actingAs($this->registrar())->test(StaffUsers::class)->assertForbidden();
    }

    public function test_staff_management_cannot_touch_alumni_accounts(): void
    {
        $alumnus = User::factory()->alumnus()->create();

        Livewire::actingAs($this->ictAdmin())->test(StaffUsers::class)->call('toggleActive', $alumnus->id)->assertNotFound();

        $this->assertTrue($alumnus->fresh()->is_active);
    }

    private function pendingClaim(array $attributes = []): AlumniProfile
    {
        $user = User::factory()->alumnus()->create();

        return AlumniProfile::factory()->pending()->create($attributes + [
            'user_id' => $user->id,
            'student_number' => null,
            'declared_student_number' => 'SU/2020/050',
            'first_name' => 'Grace',
            'last_name' => 'Achieng',
        ]);
    }
}
