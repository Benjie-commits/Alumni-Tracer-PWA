<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SurveyResponse;
use App\Models\TracerSurveyCycle;
use App\Services\Surveys\ReadableAnswers;
use App\Support\Csv;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every response to one survey as a spreadsheet, for NCHE reporting and the QA Directorate.
 * One column per question; answers appear with the wording of the version that was answered.
 */
class SurveyExportController extends Controller
{
    public function __invoke(TracerSurveyCycle $cycle): StreamedResponse
    {
        // The questions across every version, newest wording first, so a revised survey still exports as one table.
        $columns = [];
        foreach ($cycle->versions()->orderByDesc('version')->get() as $version) {
            foreach ($version->questions() as $question) {
                $columns[$question['key']] ??= $question['label'];
            }
        }

        $headings = array_merge(
            ['Student number', 'First name', 'Last name', 'Programme', 'School', 'Graduation year', 'Class of award', 'Answered on', 'Questionnaire version', 'Teaching-assistant candidate'],
            array_values($columns),
        );

        $rows = function () use ($cycle, $columns) {
            $query = SurveyResponse::query()
                ->whereHas('invitation', fn ($q) => $q->where('tracer_survey_cycle_id', $cycle->id))
                ->with(['profile.programme.department.school', 'version'])
                ->orderBy('submitted_at')->orderBy('id');

            foreach ($query->lazy(500) as $response) {
                $answers = collect(ReadableAnswers::for($response))->pluck('answer', 'key');
                $p = $response->profile;

                yield array_merge([
                    $p->student_number, $p->first_name, $p->last_name,
                    $p->programme?->name, $p->programme?->department?->school?->name,
                    $p->graduation_year, $p->class_of_award,
                    $response->submitted_at->toDateString(), $response->version->version,
                    $p->ta_flagged_at ? 'Yes' : '',
                ], array_map(fn ($key) => $answers[$key] ?? '', array_keys($columns)));
            }
        };

        return response()->streamDownload(
            fn () => Csv::stream($headings, $rows()),
            "sunates-survey-{$cycle->milestone_months}m-".now()->format('Ymd-His').'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
