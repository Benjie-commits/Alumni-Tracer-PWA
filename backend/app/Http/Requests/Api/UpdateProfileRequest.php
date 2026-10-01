<?php

namespace App\Http\Requests\Api;

use App\Enums\EmploymentStatus;
use App\Enums\FurtherStudyStatus;
use App\Support\LinkedInUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Only the alumnus-owned fields; academic history is Registrar-owned and never accepted here.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * "linkedin.com/in/someone" and "https://ug.linkedin.com/in/someone/?trk=x" both become the one
     * canonical form; anything that is not a profile address is left as typed so the rule rejects it.
     */
    protected function prepareForValidation(): void
    {
        $typed = $this->input('linkedin_url');

        if (is_string($typed)) {
            $this->merge(['linkedin_url' => LinkedInUrl::normalise($typed) ?? (trim($typed) === '' ? null : $typed)]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $phone = ['nullable', 'string', 'regex:/^\+?[0-9][0-9\s\-]{6,19}$/'];

        return [
            // The contact email is also the sign-in email, so it must not belong to another account.
            'email' => ['nullable', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->id)],
            'phone' => $phone,
            'whatsapp_number' => $phone,
            'country' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'linkedin_url' => ['nullable', 'string', 'max:255', 'regex:'.LinkedInUrl::PATTERN],
            'employment_status' => ['nullable', Rule::enum(EmploymentStatus::class)],
            'further_study_status' => ['nullable', Rule::enum(FurtherStudyStatus::class)],
            'further_study_institution' => ['nullable', 'string', 'max:255'],
            'further_study_programme' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number, e.g. +256700000000.',
            'whatsapp_number.regex' => 'Enter a valid WhatsApp number, e.g. +256700000000.',
            'linkedin_url.regex' => 'Enter the address of your LinkedIn profile, e.g. linkedin.com/in/your-name.',
        ];
    }
}
