<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class StoreOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'ot_date' => ['nullable', 'date'],
            'start' => ['nullable', 'date'],
            'start_at' => ['nullable'],
            'end_at' => ['nullable'],
            'start_time' => ['nullable', 'string'],
            'end_time' => ['nullable', 'string'],
            'ends_next_day' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'work_schedule_id' => ['nullable', 'integer', 'exists:work_schedules,id'],
            'file_path' => ['nullable', 'string', 'max:500'],
            'image' => ['nullable'],
            'remove_image' => ['nullable'],
            'file' => ['nullable'],
        ];
    }
}
