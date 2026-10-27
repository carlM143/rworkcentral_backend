<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'leave_type_id' => ['nullable', 'integer', 'exists:leave_types,id'],
            'type' => ['nullable', 'string', 'max:100'],
            'leave_policy_id' => ['nullable', 'integer', 'exists:leave_policies,id'],
            'request_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'start' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'end' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'dates' => ['nullable', 'array'],
            'file_path' => ['nullable', 'string', 'max:500'],
            'image' => ['nullable'],
            'file' => ['nullable'],
        ];
    }
}
