<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObaImage extends Model
{
    use HasFactory;

    protected $table = 'oba_images';

    protected $fillable = [
        'oba_request_id',
        'image_path',
    ];

    public function obaRequest(): BelongsTo
    {
        return $this->belongsTo(ObaRequest::class, 'oba_request_id');
    }
}