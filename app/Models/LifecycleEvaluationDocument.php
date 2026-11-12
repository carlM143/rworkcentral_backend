<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LifecycleEvaluationDocument extends Model
{
    protected $fillable = ['employee_id', 'uploaded_by', 'path', 'original_name', 'mime_type', 'size'];

    protected $hidden = ['path'];
}
