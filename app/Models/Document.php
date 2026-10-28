<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'employee_id',
        'employment_document_id',
        'file_path',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function employmentDocument()
    {
        return $this->belongsTo(EmploymentDocument::class);
    }
}
