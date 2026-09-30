<?php

namespace App\Http\Requests\Api;

use App\Enums\EmploymentStatus;
use App\Enums\FurtherStudyStatus;
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
        ];
    }
}
