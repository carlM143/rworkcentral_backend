<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmploymentDocument extends Model
{
    protected $fillable = [
        'document_code',
        'document_name',
        'category',
        'description',
        'sort_order',
    ];

    public function documents()
    {
        return $this->hasMany(Document::class);
    }
}
