<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sector extends Model
{
    protected $fillable = [
        'nombre',
        'slug',
        'dias_gestion',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'dias_gestion' => 'integer',
    ];

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
