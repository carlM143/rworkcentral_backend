<?php

namespace App\Http\Requests\Admin;

use App\Models\Department;
use App\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Personal Information
            'employee' => ['required', 'array'],
            'employee.firstname' => ['required', 'string', 'max:100'],
            'employee.lastname' => ['required', 'string', 'max:100'],
            'employee.middlename' => ['nullable', 'string', 'max:100'],
            'employee.suffix' => ['nullable', 'string', 'max:20'],
            'employee.birthday' => ['required', 'date', 'before:today'],
            'employee.gender' => ['required', Rule::in(['Male', 'Female'])],
            'employee.civil_status' => ['required', Rule::in(['Single', 'Married', 'Widowed', 'Separated', 'Annulled'])],
            'employee.profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],

            // Contact Information
            'contact' => ['required', 'array'],
            'contact.email' => ['required', 'email', 'max:255'],
            'contact.mobile' => ['required', 'string', 'max:30'],
            'contact.residential_address' => ['nullable', 'string', 'max:255'],

            // Employment Information
            'employment' => ['required', 'array'],
            'employment.employment_type_id' => ['required', 'integer', Rule::exists('employment_types', 'id')],
            'employment.department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'employment.position_id' => ['required', 'integer', Rule::exists('positions', 'id')],
            'employment.work_schedule_id' => ['required', 'integer', Rule::exists('work_schedules', 'id')],
            'employment.restday_id' => ['required', 'integer', Rule::exists('rest_days', 'id')],
            'employment.date_hired' => ['required', 'date'],

            // Government Requirements
            'government_requirements' => ['sometimes', 'array'],
            'government_requirements.*.id_name' => ['required', 'string', 'max:100'],
            'government_requirements.*.id_number' => ['required', 'string', 'max:100'],

            // Employee Salary Setup
            'salary_setup' => ['sometimes', 'array'],
            'salary_setup.salary_type_id' => ['required_with:salary_setup', 'integer', Rule::exists('salary_types', 'id')],
            'salary_setup.basic_salary' => ['nullable', 'numeric', 'gt:0'],
            'salary_setup.allowance' => ['nullable', 'numeric', 'min:0'],
            'salary_setup.bank_name' => ['nullable', 'string', 'max:255'],
            'salary_setup.account_name' => ['nullable', 'string', 'max:255'],
            'salary_setup.account_number' => ['nullable', 'string', 'max:100'],

            // Emergency Contacts
            'contact_persons' => ['sometimes', 'array'],
            'contact_persons.*.name' => ['required', 'string', 'max:255'],
            'contact_persons.*.relationship' => ['required', 'string', 'max:100'],
            'contact_persons.*.contact_number' => ['required', 'string', 'max:30'],
            'contact_persons.*.address' => ['required', 'string', 'max:255'],

            // Employee Documents
            'documents' => ['sometimes', 'array'],
            'documents.*.doc_name' => ['required', 'string', 'max:255'],
            'documents.*.file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $departmentId = (int) $this->input('employment.department_id');
                $positionId = (int) $this->input('employment.position_id');

                // Validate active department
                if (! Department::whereKey($departmentId)->where('status', true)->exists()) {
                    $validator->errors()->add(
                        'employment.department_id',
                        'Please select an active department.'
                    );
                }

                // Validate active position within selected department
                if (! Position::whereKey($positionId)
                    ->where('department_id', $departmentId)
                    ->where('status', true)
                    ->exists()) {
                    $validator->errors()->add(
                        'employment.position_id',
                        'Please select an active position belonging to the selected department.'
                    );
                }
            },
        ];
    }
}
