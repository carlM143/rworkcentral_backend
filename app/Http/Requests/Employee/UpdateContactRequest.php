<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'mobile' => ['nullable', 'string', 'max:40'],
            'residential_address' => ['nullable', 'string', 'max:500'],
            'emergency_name' => ['nullable', 'string', 'max:150'],
            'emergency_relationship' => ['nullable', 'string', 'max:80'],
            'emergency_phone' => ['nullable', 'string', 'max:40'],
            'emergency_address' => ['nullable', 'string', 'max:255'],
        ];
    }
}
