<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClienteAttachment extends Model
{
    protected $fillable = [
        'cliente_id',
        'path',
        'original_name',
        'mime_type',
        'size',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
