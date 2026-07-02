<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObservationAttachment extends Model
{
    protected $fillable = [
        'observation_id',
        'path',
        'original_name',
        'mime_type',
        'size',
    ];

    public function observacion(): BelongsTo
    {
        return $this->belongsTo(Observacion::class, 'observation_id');
    }
}
