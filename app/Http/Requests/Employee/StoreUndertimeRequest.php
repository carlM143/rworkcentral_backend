<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class StoreUndertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'undertime_date' => ['nullable', 'date'],
            'start' => ['nullable', 'date'],
            'requested_time_out' => ['nullable'],
            'scheduled_time_out' => ['nullable'],
            'requested_time' => ['nullable', 'string'],
            'scheduled_time' => ['nullable', 'string'],
            'requested_minutes' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
            'work_schedule_id' => ['nullable', 'integer', 'exists:work_schedules,id'],
            'file_path' => ['nullable', 'string', 'max:500'],
            'image' => ['nullable'],
            'file' => ['nullable'],
        ];
    }
}
