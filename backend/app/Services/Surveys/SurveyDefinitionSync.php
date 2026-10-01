<?php

namespace App\Services\Surveys;

use App\Models\TracerSurveyCycle;
use App\Models\TracerSurveyVersion;
use Illuminate\Support\Facades\DB;

/**
 * Brings the database in line with config/tracer_surveys.php. Running it again with unchanged
 * questions does nothing; changing any question creates a new immutable version, and only invitations
 * answered from then on use it.
 */
class SurveyDefinitionSync
{
    /**
     * @return array<int, string> milestone months => 'created' | 'updated' | 'unchanged'
     */
    public function sync(?array $milestones = null): array
    {
        $milestones ??= config('tracer_surveys.milestones');

        // Check everything first, so a mistake in one survey cannot leave the others half-updated.
        foreach ($milestones as $months => $survey) {
            SurveyDefinition::assertValid($survey['definition']);
        }

        $results = [];

        DB::transaction(function () use ($milestones, &$results) {
            foreach ($milestones as $months => $survey) {
                $results[$months] = $this->syncOne((int) $months, $survey);
            }
        });

        return $results;
    }

    /**
     * @param  array{title: string, definition: array<string, mixed>}  $survey
     */
    private function syncOne(int $months, array $survey): string
    {
        $cycle = TracerSurveyCycle::query()->firstOrNew(['milestone_months' => $months]);
        $isNew = ! $cycle->exists;

        $cycle->title = $survey['title'];
        $cycle->save();

        $hash = hash('sha256', json_encode($survey['definition'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $current = $cycle->current_version_id ? TracerSurveyVersion::query()->find($cycle->current_version_id) : null;

        if ($current?->definition_hash === $hash) {
            return $cycle->wasChanged('title') ? 'updated' : 'unchanged';
        }

        $version = TracerSurveyVersion::query()->create([
            'tracer_survey_cycle_id' => $cycle->id,
            'version' => ((int) $cycle->versions()->max('version')) + 1,
            'definition' => $survey['definition'],
            'definition_hash' => $hash,
        ]);

        $cycle->update(['current_version_id' => $version->id]);

        return $isNew ? 'created' : 'updated';
    }
}
