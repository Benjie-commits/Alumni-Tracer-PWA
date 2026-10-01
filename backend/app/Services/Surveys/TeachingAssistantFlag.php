<?php

namespace App\Services\Surveys;

use App\Models\AlumniProfile;

/**
 * FR-3: flag strong graduates who say they are available for teaching-assistant consideration.
 * "Strong" is the class of award (config: sunates.surveys.ta_eligible_classes); "available" is a
 * yes to the survey's TA question. The flag is advice for staff, not a decision.
 */
class TeachingAssistantFlag
{
    /**
     * @param  bool|null  $interest  the alumnus's answer; null when they did not answer, which leaves the flag as it was
     */
    public function apply(AlumniProfile $profile, ?bool $interest): void
    {
        if ($interest === null) {
            return;
        }

        if ($interest === true && $this->isStrong($profile)) {
            $profile->ta_flagged_at ??= now();

            return;
        }

        // Said no, or is not in an eligible class: make sure no stale flag remains.
        $profile->ta_flagged_at = null;
    }

    public function isStrong(AlumniProfile $profile): bool
    {
        $class = mb_strtolower(trim((string) $profile->class_of_award));

        return $class !== '' && in_array($class, config('sunates.surveys.ta_eligible_classes'), true);
    }
}
