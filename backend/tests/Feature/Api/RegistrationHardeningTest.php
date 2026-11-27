<?php

namespace Tests\Feature\Api;

use App\Models\Programme;
use App\Models\RegistrationChallenge;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\Messaging\Contracts\SmsGateway;
use App\Services\Messaging\Contracts\WhatsAppGateway;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\FakeSmsGateway;
use Tests\Support\FakeWhatsAppGateway;

class RegistrationHardeningTest extends ApiTestCase
{
    private function studentPayload(array $o = []): array
    {
        return $o + [
            'student_number' => '2201600099', 'first_name' => 'Joan', 'last_name' => 'Aol', 'email' => 'joan@sun.ac.ug',
            'programme_id' => Programme::factory()->create()->id, 'year_of_study' => 2, 'password' => 'a-good-passphrase',
        ];
    }

    public function test_student_number_alone_cannot_take_over_a_staff_recorded_student(): void
    {
        $student = Student::factory()->create(['student_number' => '2201600099', 'email' => 'joan@sun.ac.ug', 'user_id' => null]);

        $this->postJson('/api/v1/auth/register-student', $this->studentPayload(['email' => 'attacker@example.com']))
            ->assertUnprocessable()->assertJsonValidationErrors('student_number');
        $this->assertNull($student->fresh()->user_id);
    }

    public function test_a_record_with_no_email_on_file_cannot_be_claimed_by_number(): void
    {
        Student::factory()->create(['student_number' => '2201600099', 'email' => null, 'user_id' => null]);

        $this->postJson('/api/v1/auth/register-student', $this->studentPayload())->assertUnprocessable();
    }

    public function test_the_email_on_file_attaches_the_record(): void
    {
        $student = Student::factory()->create(['student_number' => '2201600099', 'email' => 'Joan@sun.ac.ug', 'user_id' => null]);

        $this->postJson('/api/v1/auth/register-student', $this->studentPayload())->assertCreated();
        $this->assertNotNull($student->fresh()->user_id);
    }

    public function test_a_brand_new_student_can_still_register(): void
    {
        $this->postJson('/api/v1/auth/register-student', $this->studentPayload())->assertCreated();
    }

    public function test_with_codes_off_a_matching_record_is_not_claimed_and_no_code_is_sent(): void
    {
        $sms = new FakeSmsGateway;
        $wa = new FakeWhatsAppGateway;
        $this->app->instance(SmsGateway::class, $sms);
        $this->app->instance(WhatsAppGateway::class, $wa);
        Mail::fake();
        Setting::put('registration.otp', false);
        $record = $this->importedRecord(['phone' => '0700 111 222']);

        $this->postJson('/api/v1/auth/register', $this->registrationPayload(['student_number' => $record->student_number, 'last_name' => $record->last_name, 'graduation_year' => $record->graduation_year]))
            ->assertSuccessful();

        $this->assertSame(0, RegistrationChallenge::query()->count());
        $this->assertSame([], $wa->sent);
        $this->assertNull($record->fresh()->user_id);
    }

    public function test_codes_are_off_by_default_in_config(): void
    {
        $this->assertFalse((require base_path('config/sunates.php'))['registration']['otp_enabled']);
    }

    public function test_only_an_ict_admin_can_switch_codes(): void
    {
        $admin = User::factory()->ictAdmin()->create();
        Livewire::actingAs($admin)->test(\App\Livewire\Admin\Settings::class)->call('toggleOtp');
        $this->assertFalse(Setting::enabled('registration.otp', true));
        $this->assertDatabaseHas('activity_logs', ['action' => 'settings.registration_otp']);

        $this->actingAs(User::factory()->registrar()->create())->get(route('admin.settings'))->assertForbidden();
    }
}
