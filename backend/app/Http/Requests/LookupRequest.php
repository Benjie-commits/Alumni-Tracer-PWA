<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** An employer's or partner's question: did this person graduate? Used by the portal and the JSON endpoint. */
class LookupRequest extends FormRequest
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
            // Who is asking: logged for accountability and to trace misuse.
            'organisation' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'name' => ['required', 'string', 'max:120'],
            'programme_id' => ['nullable', 'integer', 'exists:programmes,id'],
            'graduation_year' => ['nullable', 'integer', 'between:1950,'.((int) date('Y') + 1)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'organisation.required' => 'Tell us which organisation is asking.',
            'name.required' => 'Enter the graduate\'s full name.',
        ];
    }
}
