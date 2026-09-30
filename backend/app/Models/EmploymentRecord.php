<?php

namespace App\Models;

use App\Enums\EmploymentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employer', 'job_title', 'sector', 'employment_type',
    'start_date', 'end_date', 'is_current', 'city', 'country',
])]
class EmploymentRecord extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'employment_type' => EmploymentType::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'is_current' => 'boolean',
        ];
    }

    public function alumniProfile(): BelongsTo
    {
        return $this->belongsTo(AlumniProfile::class);
    }
}
