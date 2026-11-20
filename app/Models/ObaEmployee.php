<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObaEmployee extends Model
{
    use HasFactory;

    protected $table = 'oba_employees';

    protected $fillable = [
        'oba_request_id',
        'employee_id',
        'employee_name',
    ];

    public function obaRequest(): BelongsTo
    {
        return $this->belongsTo(ObaRequest::class, 'oba_request_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}