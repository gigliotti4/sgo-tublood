<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Área del organigrama ("sector original" del Excel): dónde trabaja la persona.
 * Distinto de {@see Sector}, que es el destino de gestión de una observación.
 */
class Area extends Model
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
