<?php

namespace App\Models;

use App\Enums\EmploymentStatus;
use App\Enums\FurtherStudyStatus;
use App\Enums\RecordSource;
use App\Enums\VerificationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'user_id', 'student_number', 'declared_student_number', 'programme_id', 'graduation_year', 'graduation_date', 'class_of_award',
    'first_name', 'last_name', 'other_names', 'gender', 'date_of_birth',
    'email', 'phone', 'whatsapp_number', 'country', 'city',
    'employment_status', 'further_study_status', 'further_study_institution', 'further_study_programme',
    'record_source', 'verification_status', 'claimed_at', 'verified_at', 'verified_by',
    'consented_at', 'profile_updated_at',
])]
class AlumniProfile extends Model
{
    use HasFactory, SoftDeletes;

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
     * Directory filters (FR-1): school, department, programme, graduation year and status.
     *
     * @param  array{school_id?: mixed, department_id?: mixed, programme_id?: mixed, graduation_year?: mixed, verification_status?: mixed}  $filters
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
            ->when($filters['verification_status'] ?? null, fn (Builder $q, $status) => $q->where('verification_status', $status));
    }
}
