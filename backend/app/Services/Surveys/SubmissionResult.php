<?php

namespace App\Services\Surveys;

use App\Models\SurveyResponse;

/** created is false when this exact submission had already been recorded (a harmless retry). */
final readonly class SubmissionResult
{
    public function __construct(
        public SurveyResponse $response,
        public bool $created,
    ) {}
}
