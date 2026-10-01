<?php

namespace App\Models;

use App\Enums\FollowUpOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['alumni_profile_id', 'checked_by', 'outcome', 'note'])]
class FollowUpCheck extends Model
{
    protected $table = 'followup_checks';

    protected function casts(): array
    {
        return ['outcome' => FollowUpOutcome::class];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AlumniProfile::class, 'alumni_profile_id');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }
}
