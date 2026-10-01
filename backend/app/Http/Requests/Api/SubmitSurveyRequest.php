<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class SubmitSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Answer contents are validated against the questionnaire itself (SurveyAnswers); this only
     * checks the envelope.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Chosen by the phone before sending so a retry after a dropped connection is recognised.
            'submission_id' => ['required', 'uuid'],
            'answers' => ['required', 'array', 'max:60'],
            // The questionnaire version the alumnus was shown.
            'version' => ['nullable', 'integer'],
        ];
    }
}
