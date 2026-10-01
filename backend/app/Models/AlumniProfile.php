<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use App\Enums\FurtherStudyStatus;
use App\Enums\NotificationChannel;
use App\Enums\RecordSource;
use App\Enums\VerificationStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

#[Fillable([
    'user_id', 'student_number', 'declared_student_number', 'programme_id', 'graduation_year', 'graduation_date', 'class_of_award',
    'first_name', 'last_name', 'other_names', 'gender', 'date_of_birth',
    'email', 'phone', 'whatsapp_number', 'country', 'city',
    'employment_status', 'further_study_status', 'further_study_institution', 'further_study_programme',
    'record_source', 'verification_status', 'claimed_at', 'verified_at', 'verified_by',
    'consented_at', 'profile_updated_at',
    'sms_opt_out_at', 'whatsapp_opt_out_at', 'last_survey_completed_at', 'ta_flagged_at',
])]
class AlumniProfile extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        // Every profile gets its own "stop messaging me" token the moment it exists. It is set
        // here rather than being mass-assignable, so nothing a client sends can choose it.
        static::creating(function (self $profile) {
            $profile->unsubscribe_token ??= Str::random(24);
        });
    }

    /** Fields an alumnus may change themselves; academic history stays Registrar-owned. */
    public const SELF_SERVICE_FIELDS = [
        'email', 'phone', 'whatsapp_number', 'country', 'city',
        'employment_status', 'further_study_status', 'further_study_institution', 'further_study_programme',
    ];

    protected function casts(): array
    {
        return [
            'graduation_date' => 'date',
            'date_of_birth' => 'date',
            'employment_status' => EmploymentStatus::class,
            'further_study_status' => FurtherStudyStatus::class,
            'record_source' => RecordSource::class,
            'verification_status' => VerificationStatus::class,
            'claimed_at' => 'datetime',
            'verified_at' => 'datetime',
            'consented_at' => 'datetime',
            'profile_updated_at' => 'datetime',
            'sms_opt_out_at' => 'datetime',
            'whatsapp_opt_out_at' => 'datetime',
            'last_survey_completed_at' => 'datetime',
            'ta_flagged_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class);
    }

    public function employmentRecords(): HasMany
    {
        return $this->hasMany(EmploymentRecord::class);
    }

    public function surveyInvitations(): HasMany
    {
        return $this->hasMany(SurveyInvitation::class);
    }

    public function surveyResponses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    public function credentialLinks(): HasMany
    {
        return $this->hasMany(CredentialLink::class);
    }

    public function hasOptedOut(NotificationChannel $channel): bool
    {
        return match ($channel) {
            NotificationChannel::Sms => $this->sms_opt_out_at !== null,
            NotificationChannel::Whatsapp => $this->whatsapp_opt_out_at !== null,
        };
    }

    /** Stop (or resume) messages on one channel; null channel means all of them. */
    public function setOptOut(?NotificationChannel $channel, bool $optedOut): void
    {
        $value = $optedOut ? now() : null;

        if ($channel === null || $channel === NotificationChannel::Sms) {
            $this->sms_opt_out_at = $optedOut ? ($this->sms_opt_out_at ?? $value) : null;
        }
        if ($channel === null || $channel === NotificationChannel::Whatsapp) {
            $this->whatsapp_opt_out_at = $optedOut ? ($this->whatsapp_opt_out_at ?? $value) : null;
        }
    }

    /** The graduation date used for survey milestones: the real date, else the end of the graduation year. */
    public function milestoneBaseDate(): ?Carbon
    {
        if ($this->graduation_date !== null) {
            return $this->graduation_date->copy()->startOfDay();
        }

        if ($this->graduation_year === null) {
            return null;
        }

        [$month, $day] = array_map('intval', explode('-', config('sunates.surveys.fallback_graduation_month_day')));

        return Carbon::create($this->graduation_year, $month, $day)->startOfDay();
    }

    /**
     * Graduates we can confirm to an employer (FR-6): a record the Registrar actually holds (it has a
     * student number), whose graduation has happened. A claim someone typed in themselves, even one
     * staff approved as new, is never confirmed on its own say-so.
     */
    public function scopeVerifiable(Builder $query): Builder
    {
        $today = now(config('sunates.timezone'))->toDateString();

        return $query
            ->whereIn('verification_status', [VerificationStatus::Unclaimed, VerificationStatus::Verified])
            ->whereNotNull('student_number')
            ->whereNotNull('graduation_year')
            ->where('graduation_year', '<=', (int) substr($today, 0, 4))
            ->where(fn (Builder $q) => $q->whereNull('graduation_date')->orWhere('graduation_date', '<=', $today));
    }

    /** Strong graduates who said they are available for teaching-assistant work (FR-3). */
    public function scopeTaCandidates(Builder $query): Builder
    {
        return $query->whereNotNull('ta_flagged_at');
    }

    public function getFullNameAttribute(): string
    {
        return trim(implode(' ', array_filter([$this->first_name, $this->other_names, $this->last_name])));
    }

    public function isClaimed(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Free-text match on name and student number, and on contact details only when the caller is
     * allowed to see them (otherwise searching would leak what the page hides).
     */
    public function scopeSearch(Builder $query, ?string $term, bool $includeContact = true): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(function (Builder $q) use ($like, $includeContact) {
            $q->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('other_names', 'like', $like)
                ->orWhere('student_number', 'like', $like);

            if ($includeContact) {
                $q->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like);
            }
        });
    }

    /**
     * Directory filters (FR-1): school, department, programme, graduation year and status, plus
     * teaching-assistant candidates only (FR-3).
     *
     * @param  array{school_id?: mixed, department_id?: mixed, programme_id?: mixed, graduation_year?: mixed, verification_status?: mixed, ta_candidate?: mixed}  $filters
     */
    public function scopeFilter(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['school_id'] ?? null, fn (Builder $q, $id) => $q->whereIn(
                'programme_id',
                Programme::query()
                    ->select('programmes.id')
                    ->join('departments', 'departments.id', '=', 'programmes.department_id')
                    ->where('departments.school_id', $id)
            ))
            ->when($filters['department_id'] ?? null, fn (Builder $q, $id) => $q->whereIn(
                'programme_id',
                Programme::query()->select('id')->where('department_id', $id)
            ))
            ->when($filters['programme_id'] ?? null, fn (Builder $q, $id) => $q->where('programme_id', $id))
            ->when($filters['graduation_year'] ?? null, fn (Builder $q, $year) => $q->where('graduation_year', $year))
            ->when($filters['verification_status'] ?? null, fn (Builder $q, $status) => $q->where('verification_status', $status))
            ->when($filters['ta_candidate'] ?? null, fn (Builder $q) => $q->whereNotNull('ta_flagged_at'));
    }
}
