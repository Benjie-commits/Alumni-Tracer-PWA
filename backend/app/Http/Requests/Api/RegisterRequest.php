<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
        return [
            'student_number' => ['required', 'string', 'max:64'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'graduation_year' => ['required', 'integer', 'between:1950,'.((int) date('Y') + 1)],
            'programme_id' => ['nullable', 'integer', 'exists:programmes,id'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9][0-9\s\-]{6,19}$/'],
            'password' => ['required', 'string', Password::min(8)->max(128)],
            'consent' => ['accepted'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number, e.g. +256700000000.',
            'consent.accepted' => 'You must agree to the data-protection notice to register.',
        ];
    }
}
