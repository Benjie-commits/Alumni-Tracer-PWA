<?php

namespace Tests\Feature\Api;

use App\Enums\RecordSource;
use App\Enums\VerificationStatus;
use App\Mail\RegistrationCodeMail;
use App\Models\AlumniProfile;
use App\Models\RegistrationChallenge;
use App\Models\User;
use App\Services\Messaging\Contracts\SmsGateway;
use App\Services\Messaging\Contracts\WhatsAppGateway;
use App\Services\Messaging\TransientGatewayException;
use Illuminate\Support\Facades\Mail;
use Tests\Support\FakeSmsGateway;
use Tests\Support\FakeWhatsAppGateway;

/**
 * FR-2 registration. A match on student number + surname + year no longer claims a record by itself:
 * a code goes to the contact details the Registrar holds, and only that code claims it.
 */
class RegistrationTest extends ApiTestCase
{
    private FakeSmsGateway $sms;

    private FakeWhatsAppGateway $whatsapp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new FakeSmsGateway;
        $this->whatsapp = new FakeWhatsAppGateway;
        $this->app->instance(SmsGateway::class, $this->sms);
        $this->app->instance(WhatsAppGateway::class, $this->whatsapp);
        Mail::fake();
    }

    /** An imported record whose Registrar data includes a phone, so a code can be sent. */
    private function reachableRecord(array $overrides = []): AlumniProfile
    {
        return $this->importedRecord($overrides + ['phone' => '0700 111 222']);
    }

    private function lastCode(): string
    {
        $last = end($this->whatsapp->sent);

        return $last['params'][0];
    }

    /** Register, then type back the code that was sent. */
    private function registerAndVerify(array $payload = []): \Illuminate\Testing\TestResponse
    {
        $challenge = $this->postJson('/api/v1/auth/register', $this->registrationPayload($payload))
            ->assertAccepted()
            ->json('data.challenge');

        return $this->postJson('/api/v1/auth/register/verify', ['challenge' => $challenge, 'code' => $this->lastCode()]);
    }

    // ---- a matching record: a code to the contact on record -------------------------------------

    public function test_a_match_sends_a_code_to_the_phone_on_record_and_creates_nothing_yet(): void
    {
        $record = $this->reachableRecord();

        $this->postJson('/api/v1/auth/register', $this->registrationPayload())
            ->assertAccepted()
            ->assertJsonPath('data.status', 'code_sent')
            ->assertJsonPath('data.channel', 'whatsapp')
            ->assertJsonPath('data.sent_to', '•••••••222')
            ->assertJsonMissingPath('token');

        // To the Registrar's number, never to the number the registering person typed.
        $this->assertSame('+256700111222', $this->whatsapp->sent[0]['to']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->lastCode());

        $this->assertSame(0, User::count());
        $this->assertNull($record->fresh()->user_id, 'nothing is claimed until the code comes back');
    }

    public function test_the_right_code_claims_and_verifies_the_record(): void
    {
        $record = $this->reachableRecord();

        $this->registerAndVerify()
            ->assertCreated()
            ->assertJsonPath('user.role', 'alumni')
            ->assertJsonPath('user.profile.verification_status.value', 'verified')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'profile' => ['student_number']]]);

        $record->refresh();
        $this->assertNotNull($record->user_id);
        $this->assertSame(VerificationStatus::Verified, $record->verification_status);
        $this->assertSame('amina@example.com', $record->email);
        $this->assertNotNull($record->consented_at);
        $this->assertSame(1, AlumniProfile::count(), 'a match must claim the existing record, not create a second one');

        // The password they chose at step one is the one that works.
        $this->postJson('/api/v1/auth/login', ['email' => 'amina@example.com', 'password' => 'a-good-passphrase'])->assertOk();
    }

    public function test_matching_ignores_case_and_extra_whitespace(): void
    {
        $this->reachableRecord();

        $this->registerAndVerify([
            'student_number' => '  su/2021/014 ',
            'last_name' => ' OKELLO ',
        ])->assertCreated()->assertJsonPath('user.profile.verification_status.value', 'verified');
    }

    public function test_a_record_with_only_an_email_gets_the_code_by_email(): void
    {
        $this->importedRecord(['email' => 'amina.registrar@example.com']);

        $this->postJson('/api/v1/auth/register', $this->registrationPayload())
            ->assertAccepted()
            ->assertJsonPath('data.channel', 'email')
            ->assertJsonPath('data.sent_to', 'a•••@example.com');

        Mail::assertSent(RegistrationCodeMail::class, fn (RegistrationCodeMail $mail) => $mail->hasTo('amina.registrar@example.com')
            && preg_match('/^\d{6}$/', $mail->code) === 1);
    }

    public function test_a_match_with_no_contact_details_on_record_goes_to_the_registrar(): void
    {
        $record = $this->importedRecord(); // no phone, no email

        $this->postJson('/api/v1/auth/register', $this->registrationPayload())
            ->assertCreated()
            ->assertJsonPath('user.profile.verification_status.value', 'pending');

        $this->assertNull($record->fresh()->user_id, 'the record waits for staff to link it');
        $this->assertSame([], $this->whatsapp->sent);
    }

    public function test_a_wrong_code_counts_down_and_then_stops_working(): void
    {
        $this->reachableRecord();
        $challenge = $this->postJson('/api/v1/auth/register', $this->registrationPayload())->json('data.challenge');
        $wrong = $this->lastCode() === '000000' ? '111111' : '000000';

        $this->postJson('/api/v1/auth/register/verify', ['challenge' => $challenge, 'code' => $wrong])
            ->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'That code is not right. You have 4 tries left.');

        foreach (range(1, 4) as $_) {
            $this->postJson('/api/v1/auth/register/verify', ['challenge' => $challenge, 'code' => $wrong])->assertUnprocessable();
        }

        // Even the right code is refused once the tries are used up.
        $this->postJson('/api/v1/auth/register/verify', ['challenge' => $challenge, 'code' => $this->lastCode()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
        $this->assertSame(0, User::count());
    }

    public function test_an_expired_code_is_refused(): void
    {
        $this->reachableRecord();
        $challenge = $this->postJson('/api/v1/auth/register', $this->registrationPayload())->json('data.challenge');

        $this->travel(16)->minutes();

        $this->postJson('/api/v1/auth/register/verify', ['challenge' => $challenge, 'code' => $this->lastCode()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $this->reachableRecord();
        $challenge = $this->postJson('/api/v1/auth/register', $this->registrationPayload())->json('data.challenge');
        $code = $this->lastCode();

        $this->postJson('/api/v1/auth/register/verify', ['challenge' => $challenge, 'code' => $code])->assertCreated();
        $this->postJson('/api/v1/auth/register/verify', ['challenge' => $challenge, 'code' => $code])->assertUnprocessable();
    }

    public function test_only_the_newest_code_for_a_record_works(): void
    {
        $this->reachableRecord();
        $first = $this->postJson('/api/v1/auth/register', $this->registrationPayload())->json('data.challenge');
        $firstCode = $this->lastCode();

        $this->postJson('/api/v1/auth/register', $this->registrationPayload(['email' => 'second@example.com']))->assertAccepted();

        $this->postJson('/api/v1/auth/register/verify', ['challenge' => $first, 'code' => $firstCode])->assertUnprocessable();
    }

    public function test_one_record_cannot_be_flooded_with_codes(): void
    {
        $this->reachableRecord();
        config(['sunates.registration.max_per_record_per_day' => 2]);

        $this->postJson('/api/v1/auth/register', $this->registrationPayload())->assertAccepted();
        $this->postJson('/api/v1/auth/register', $this->registrationPayload(['email' => 'b@example.com']))->assertAccepted();
        $this->postJson('/api/v1/auth/register', $this->registrationPayload(['email' => 'c@example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_number');

        $this->assertCount(2, $this->whatsapp->sent);
    }

    public function test_a_code_can_be_resent_after_a_minute_and_the_old_one_stops_working(): void
    {
        $this->reachableRecord();
        $challenge = $this->postJson('/api/v1/auth/register', $this->registrationPayload())->json('data.challenge');
        $oldCode = $this->lastCode();

        $this->postJson('/api/v1/auth/register/resend', ['challenge' => $challenge])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/register/resend', ['challenge' => $challenge])->assertAccepted();
        $this->assertCount(2, $this->whatsapp->sent);

        if ($oldCode !== $this->lastCode()) {
            $this->postJson('/api/v1/auth/register/verify', ['challenge' => $challenge, 'code' => $oldCode])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/register/verify', ['challenge' => $challenge, 'code' => $this->lastCode()])->assertCreated();
    }

    public function test_a_temporary_messaging_failure_asks_the_person_to_try_again(): void
    {
        $this->reachableRecord();
        $this->whatsapp->failWith = new TransientGatewayException('Timed out');

        $this->postJson('/api/v1/auth/register', $this->registrationPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_number');

        $this->assertSame(0, User::count());
        $this->assertSame(0, RegistrationChallenge::count());
    }

    public function test_the_challenge_never_reveals_the_code_or_the_password(): void
    {
        $this->reachableRecord();
        $this->postJson('/api/v1/auth/register', $this->registrationPayload());

        $stored = RegistrationChallenge::sole();
        $this->assertStringNotContainsString($this->lastCode(), $stored->getRawOriginal('code_hash'));
        $this->assertStringNotContainsString('a-good-passphrase', (string) $stored->getRawOriginal('payload'));
        $this->assertStringNotContainsString('amina@example.com', (string) $stored->getRawOriginal('payload'), 'the payload is encrypted at rest');
    }

    // ---- anything else: a pending claim for the Registrar -----------------------------------------

    public function test_a_mismatch_creates_a_pending_claim_and_leaves_the_record_alone(): void
    {
        $record = $this->reachableRecord();

        $response = $this->postJson('/api/v1/auth/register', $this->registrationPayload(['last_name' => 'Okelo']));

        $response->assertCreated()->assertJsonPath('user.profile.verification_status.value', 'pending');

        $this->assertNull($record->fresh()->user_id, 'the imported record must stay unclaimed');
        $this->assertSame([], $this->whatsapp->sent, 'a mismatch never messages the record holder');

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
        $this->reachableRecord();
        $this->registerAndVerify()->assertCreated();

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

    public function test_an_email_taken_while_the_code_was_on_its_way_is_refused_at_step_two(): void
    {
        $this->reachableRecord();
        $challenge = $this->postJson('/api/v1/auth/register', $this->registrationPayload())->json('data.challenge');

        User::factory()->alumnus()->create(['email' => 'amina@example.com']);

        $this->postJson('/api/v1/auth/register/verify', ['challenge' => $challenge, 'code' => $this->lastCode()])
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
        $this->reachableRecord();

        $this->postJson('/api/v1/auth/register', $this->registrationPayload(['graduation_year' => 1800]))->assertUnprocessable();

        $this->assertSame(0, User::count());
        $this->assertSame(1, AlumniProfile::count());
        $this->assertSame(0, RegistrationChallenge::count());
    }
}
