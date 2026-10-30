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
            'dates' => ['nullable', 'array', 'min:1'],
            'dates.*' => ['required', 'array'],
            'dates.*.leave_date' => ['required', 'date', 'distinct'],
            'dates.*.day_type' => ['required', 'in:whole_day,half_day'],
            'dates.*.day_value' => ['nullable', 'numeric', 'in:0.5,1'],
            'dates.*.requested_pay_type' => ['nullable', 'in:paid,unpaid'],
            'file_path' => ['nullable', 'string', 'max:500'],
            'file' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'image' => ['nullable'],
            'remove_image' => ['nullable'],
            'requested_pay_type' => ['nullable', 'in:paid,unpaid,mixed'],
        ];
    }
}
