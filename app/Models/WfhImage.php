<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WfhImage extends Model
{
    protected $table = 'wfh_images';

    protected $fillable = [
        'wfh_tracker_id',
        'image_path',
    ];

    public function tracker(): BelongsTo
    {
        return $this->belongsTo(
            WfhTracker::class,
            'wfh_tracker_id'
        );
    }
}
