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
            // The contact email is also the sign-in email, so it must not belong to another account, and
            // it cannot be cleared (the account would be left with no way to sign in or reset a password).
            'email' => ['sometimes', 'required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($this->user()->id)],
            // Changing the sign-in email takes the account with it, so a stolen token or an unattended
            // phone must not be enough: the current password is required for that one change.
            'current_password' => [Rule::requiredIf(fn () => $this->changesSignInEmail()), 'nullable', 'string', 'current_password:sanctum'],
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
            'email.required' => 'Your email is also how you sign in, so it cannot be left empty.',
            'current_password.required' => 'Enter your current password to change your sign-in email.',
            'current_password.current_password' => 'That is not your current password.',
        ];
    }

    public function changesSignInEmail(): bool
    {
        $typed = $this->input('email');

        return is_string($typed) && $typed !== ''
            && mb_strtolower(trim($typed)) !== mb_strtolower((string) $this->user()->email);
    }
}
