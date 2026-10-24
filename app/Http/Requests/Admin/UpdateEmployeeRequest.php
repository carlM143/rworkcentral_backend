<?php

namespace App\Http\Requests\Admin;

use App\Models\Department;
use App\Models\Position;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Personal Information
            'employee' => ['sometimes', 'array'],
            'employee.firstname' => ['sometimes', 'required', 'string', 'max:100'],
            'employee.lastname' => ['sometimes', 'required', 'string', 'max:100'],
            'employee.middlename' => ['sometimes', 'nullable', 'string', 'max:100'],
            'employee.suffix' => ['sometimes', 'nullable', 'string', 'max:20'],
            'employee.birthday' => ['sometimes', 'required', 'date', 'before:today'],
            'employee.gender' => ['sometimes', 'required', Rule::in(['Male', 'Female'])],
            'employee.civil_status' => ['sometimes', 'required', Rule::in(['Single', 'Married', 'Widowed', 'Separated', 'Annulled'])],
            'employee.profile_photo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],

            // Contact Information
            'contact' => ['sometimes', 'array'],
            'contact.email' => ['sometimes', 'required', 'email', 'max:255'],
            'contact.mobile' => ['sometimes', 'required', 'string', 'max:30'],
            'contact.residential_address' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Employment Information
            'employment' => ['sometimes', 'array'],
            'employment.employment_type_id' => ['sometimes', 'required', 'integer', Rule::exists('employment_types', 'id')],
            'employment.department_id' => ['sometimes', 'required', 'integer', Rule::exists('departments', 'id')],
            'employment.position_id' => ['sometimes', 'required', 'integer', Rule::exists('positions', 'id')],
            'employment.work_schedule_id' => ['sometimes', 'required', 'integer', Rule::exists('work_schedules', 'id')],
            'employment.restday_id' => ['sometimes', 'required', 'integer', Rule::exists('rest_days', 'id')],
            'employment.date_hired' => ['sometimes', 'required', 'date'],

            // Employee Salary Setup
            'salary_setup' => ['sometimes', 'array'],
            'salary_setup.salary_type_id' => ['required_with:salary_setup', 'integer', Rule::exists('salary_types', 'id')],
            'salary_setup.basic_salary' => ['nullable', 'numeric', 'gt:0'],
            'salary_setup.allowance' => ['nullable', 'numeric', 'min:0'],
            'salary_setup.bank_name' => ['nullable', 'string', 'max:255'],
            'salary_setup.account_name' => ['nullable', 'string', 'max:255'],
            'salary_setup.account_number' => ['nullable', 'string', 'max:100'],

            // Employee Documents
            'documents' => ['sometimes', 'array'],
            'documents.*.employment_document_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('employment_documents', 'id'),
            ],
            'documents.*.submitted' => ['sometimes', 'boolean'],
            'documents.*.file' => ['sometimes', 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || ! $this->has('employment')) {
                    return;
                }

                $employee = $this->route('employee');
                $currentEmployment = $employee?->employments()->latest('id')->first();

                $departmentId = (int) $this->input(
                    'employment.department_id',
                    $currentEmployment?->department_id
                );

                $positionId = (int) $this->input(
                    'employment.position_id',
                    $currentEmployment?->position_id
                );

                // Validate active department
                if ($this->filled('employment.department_id')
                    && ! Department::whereKey($departmentId)->where('status', true)->exists()) {
                    $validator->errors()->add(
                        'employment.department_id',
                        'Please select an active department.'
                    );
                }

                // Validate position matches department
                if (($this->filled('employment.department_id') || $this->filled('employment.position_id'))
                    && ! Position::whereKey($positionId)
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
