<?php

namespace App\Services;

use App\Enums\VerificationStatus;
use App\Models\AlumniProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registrar review of self-declared ("pending") alumni.
 */
class VerificationService
{
    public function approve(AlumniProfile $profile, User $staff): void
    {
        $this->assertPending($profile);

        $profile->update([
            'verification_status' => VerificationStatus::Verified,
            'verified_at' => now(),
            'verified_by' => $staff->id,
        ]);
    }

    public function reject(AlumniProfile $profile, User $staff): void
    {
        $this->assertPending($profile);

        $profile->update([
            'verification_status' => VerificationStatus::Rejected,
            'verified_at' => now(),
            'verified_by' => $staff->id,
        ]);
    }

    /**
     * The alumnus really is an imported record (e.g. a misspelt surname stopped the automatic
     * match): attach their account to that record and discard the self-declared shell.
     */
    public function linkToRecord(AlumniProfile $pending, AlumniProfile $record, User $staff): AlumniProfile
    {
        $this->assertPending($pending);

        return DB::transaction(function () use ($pending, $record, $staff) {
            $record = AlumniProfile::query()->lockForUpdate()->findOrFail($record->id);

            if ($record->isClaimed()) {
                throw ValidationException::withMessages(['record' => 'That record already belongs to another account.']);
            }

            $userId = $pending->user_id;
            // user_id is unique, so release it from the shell before handing it to the record.
            $pending->update(['user_id' => null]);

            $record->fill([
                'user_id' => $userId,
                'email' => $pending->email ?? $record->email,
                'phone' => $pending->phone ?? $record->phone,
                'whatsapp_number' => $pending->whatsapp_number ?? $record->whatsapp_number,
                'country' => $pending->country ?? $record->country,
                'city' => $pending->city ?? $record->city,
                'employment_status' => $pending->employment_status ?? $record->employment_status,
                'further_study_status' => $pending->further_study_status ?? $record->further_study_status,
                'further_study_institution' => $pending->further_study_institution ?? $record->further_study_institution,
                'further_study_programme' => $pending->further_study_programme ?? $record->further_study_programme,
                'verification_status' => VerificationStatus::Verified,
                'claimed_at' => $pending->claimed_at ?? now(),
                'verified_at' => now(),
                'verified_by' => $staff->id,
                'consented_at' => $pending->consented_at,
                'profile_updated_at' => $pending->profile_updated_at,
            ])->save();

            $pending->employmentRecords()->update(['alumni_profile_id' => $record->id]);
            $pending->forceDelete();

            return $record;
        });
    }

    /**
     * Unclaimed imported records that might be the same person as a pending claim.
     *
     * @return Collection<int, AlumniProfile>
     */
    public function candidateRecords(AlumniProfile $pending)
    {
        return AlumniProfile::query()
            ->whereNull('user_id')
            ->where(function ($q) use ($pending) {
                $q->where('last_name', $pending->last_name);

                if ($pending->declared_student_number) {
                    $q->orWhere('student_number', $pending->declared_student_number);
                }
            })
            ->when($pending->graduation_year, fn ($q, $year) => $q->orderByRaw('graduation_year = ? desc', [$year]))
            ->with('programme.department.school')
            ->limit(10)
            ->get();
    }

    private function assertPending(AlumniProfile $profile): void
    {
        if ($profile->verification_status !== VerificationStatus::Pending) {
            throw ValidationException::withMessages(['profile' => 'Only pending profiles can be reviewed.']);
        }
    }
}
