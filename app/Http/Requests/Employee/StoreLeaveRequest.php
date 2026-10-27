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
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'leave_policy_id' => ['nullable', 'integer', 'exists:leave_policies,id'],
            'request_date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'dates' => ['nullable', 'array'],
            'dates.*' => ['required', 'date'],
            'file_path' => ['nullable', 'string', 'max:500'],
        ];
    }
}
