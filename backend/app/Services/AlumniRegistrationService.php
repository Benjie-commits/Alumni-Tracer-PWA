<?php

namespace App\Services;

use App\Enums\RecordSource;
use App\Enums\RoleSlug;
use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\RegistrationChallenge;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Registration\RegistrationCodeNotSent;
use App\Services\Registration\RegistrationCodeSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * FR-2: an alumnus registers and is verified against Registrar records.
 *
 * Student number + surname + graduation year must all match an unclaimed imported record. Those three
 * facts are often known to classmates, so a match alone does not claim the record: a one-time code is
 * sent to the phone or email the Registrar already holds for it, and the record is claimed (verified)
 * only when the code is typed back (verifyCode()). A matching record with no contact details, and
 * anything that does not match, is accepted as a self-declared claim in 'pending' status for the
 * Registrar to review, so alumni missing from the spreadsheets are not locked out.
 */
class AlumniRegistrationService
{
    public function __construct(private readonly RegistrationCodeSender $codes) {}

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
     * @return AlumniProfile|RegistrationChallenge a pending profile (account created), or a code sent to the record's contact
     */
    public function register(array $data, ?string $ip = null): AlumniProfile|RegistrationChallenge
    {
        $studentNumber = $this->normaliseStudentNumber($data['student_number']);
        $lastName = $this->squish($data['last_name']);
        $year = (int) $data['graduation_year'];

        $record = AlumniProfile::query()
            ->where('student_number', $studentNumber)
            ->where('last_name', $lastName)
            ->where('graduation_year', $year)
            ->first();

        if ($record?->isClaimed()) {
            throw $this->alreadyRegistered();
        }

        if ($record !== null && self::otpEnabled()) {
            // Sent outside any transaction: it is a call to a messaging provider.
            $challenge = $this->challenge($record, $data, $ip);
            if ($challenge !== null) {
                return $challenge;
            }
            // The record holds no phone or email to prove ownership with: the Registrar decides instead.
            // The same happens while codes are switched off: nothing is claimed without proof.
        }

        return DB::transaction(fn () => $this->declare($data, $studentNumber, $lastName, $year));
    }

    public static function otpEnabled(): bool
    {
        return Setting::enabled('registration.otp', (bool) config('sunates.registration.otp_enabled'));
    }

    /**
     * The code came back: claim the record and create the account.
     *
     * @throws ValidationException wrong, expired or used-up code; record taken meanwhile; email taken meanwhile
     */
    public function verifyCode(string $token, string $code): AlumniProfile
    {
        $id = RegistrationChallenge::query()->where('token', $token)->value('id');
        $expired = ValidationException::withMessages(['code' => 'This code has expired or has already been used. Please register again to get a new one.']);

        if ($id === null) {
            throw $expired;
        }

        // Everything under one lock: two guesses racing cannot both be counted as the same attempt, and
        // a wrong guess is recorded before the lock is released.
        $outcome = DB::transaction(function () use ($id, $code) {
            $challenge = RegistrationChallenge::query()->lockForUpdate()->find($id);

            if (! $challenge?->isUsable()) {
                return 'expired';
            }

            if (! Hash::check($code, $challenge->code_hash)) {
                $challenge->increment('attempts');

                return (int) config('sunates.registration.max_attempts') - $challenge->attempts;
            }

            $record = AlumniProfile::query()->lockForUpdate()->find($challenge->alumni_profile_id);

            if ($record === null || $record->isClaimed()) {
                throw $this->alreadyRegistered('code');
            }

            $payload = $challenge->payload;
            if (User::query()->where('email', $payload['email'])->exists()) {
                throw ValidationException::withMessages(['email' => 'This email is now used by another account. Please register again with a different email.']);
            }

            $challenge->update(['consumed_at' => now()]);

            return $this->claim($record, [
                'email' => $payload['email'],
                'phone' => $payload['phone'] ?? null,
                'password' => $payload['password_hash'],
            ]);
        });

        if ($outcome instanceof AlumniProfile) {
            return $outcome;
        }
        if ($outcome === 'expired') {
            throw $expired;
        }

        throw ValidationException::withMessages(['code' => $outcome > 0
            ? "That code is not right. You have {$outcome} ".($outcome === 1 ? 'try' : 'tries').' left.'
            : 'That code is not right, and no tries are left. Please register again to get a new one.']);
    }

    /**
     * Another code for the same registration (it did not arrive, or expired).
     *
     * @throws ValidationException
     */
    public function resendCode(string $token): RegistrationChallenge
    {
        $challenge = RegistrationChallenge::query()->with('profile')->where('token', $token)->first();
        $config = config('sunates.registration');

        if (! $challenge || $challenge->consumed_at !== null || $challenge->attempts >= $config['max_attempts'] || $challenge->profile === null) {
            throw ValidationException::withMessages(['code' => 'This registration can no longer be completed. Please register again.']);
        }
        if ($challenge->sends >= $config['max_sends']) {
            throw ValidationException::withMessages(['code' => 'No more codes can be sent for this registration. Use the last one, or register again later.']);
        }
        if ($challenge->last_sent_at->greaterThan(now()->subSeconds($config['resend_after_seconds']))) {
            throw ValidationException::withMessages(['code' => 'Please wait a minute before asking for another code.']);
        }
        $this->assertRecordNotFlooded($challenge->profile);

        $code = $this->newCode();
        $sent = $this->sendCode($challenge->profile, $code);
        if ($sent === null) {
            throw ValidationException::withMessages(['code' => 'We can no longer reach the contact details on your record. Please contact the Registrar\'s office.']);
        }

        $challenge->update([
            'code_hash' => Hash::make($code),
            'channel' => $sent['channel'],
            'destination_hint' => $sent['hint'],
            'sends' => $challenge->sends + 1,
            'last_sent_at' => now(),
            'expires_at' => now()->addMinutes((int) $config['code_ttl_minutes']),
        ]);

        return $challenge;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function challenge(AlumniProfile $record, array $data, ?string $ip): ?RegistrationChallenge
    {
        $this->assertRecordNotFlooded($record);

        $code = $this->newCode();
        $sent = $this->sendCode($record, $code);
        if ($sent === null) {
            return null;
        }

        // Only the newest code for a record works.
        RegistrationChallenge::query()
            ->where('alumni_profile_id', $record->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);

        return RegistrationChallenge::query()->create([
            'token' => Str::random(48),
            'alumni_profile_id' => $record->id,
            'code_hash' => Hash::make($code),
            'channel' => $sent['channel'],
            'destination_hint' => $sent['hint'],
            'payload' => [
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                // Hashed now: the plain password is never stored, even encrypted.
                'password_hash' => Hash::make($data['password']),
            ],
            'last_sent_at' => now(),
            'expires_at' => now()->addMinutes((int) config('sunates.registration.code_ttl_minutes')),
            'ip_address' => $ip,
        ]);
    }

    /** @return array{channel: string, hint: string}|null */
    private function sendCode(AlumniProfile $record, string $code): ?array
    {
        try {
            return $this->codes->send($record, $code);
        } catch (RegistrationCodeNotSent) {
            throw ValidationException::withMessages(['student_number' => 'We could not send your code just now. Please try again in a few minutes.']);
        }
    }

    /** However many people ask, one record is sent only a few codes a day. */
    private function assertRecordNotFlooded(AlumniProfile $record): void
    {
        $sentToday = (int) RegistrationChallenge::query()
            ->where('alumni_profile_id', $record->id)
            ->where('created_at', '>=', now()->subDay())
            ->sum('sends');

        if ($sentToday >= (int) config('sunates.registration.max_per_record_per_day')) {
            throw ValidationException::withMessages([
                'student_number' => 'Too many codes have been sent for this record today. Try again tomorrow, or contact the Registrar\'s office.',
            ]);
        }
    }

    private function newCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function alreadyRegistered(string $field = 'student_number'): ValidationException
    {
        return ValidationException::withMessages([
            $field => 'This record is already registered. Sign in instead, or contact the Registrar\'s office if this was not you.',
        ]);
    }

    /**
     * @param  array{email: string, phone?: ?string, password: string}  $data  password may already be hashed
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
            // The model's "hashed" cast hashes a plain password and keeps one that is already hashed.
            'password' => $data['password'],
            'role_id' => Role::idFor(RoleSlug::Alumni),
        ]);
    }

    public function normaliseStudentNumber(string $value): string
    {
        return \App\Support\StudentNumber::normalise($value);
    }

    private function squish(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
