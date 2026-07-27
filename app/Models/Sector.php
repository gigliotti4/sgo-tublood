<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sector extends Model
{
    /**
     * Garantía de Calidad, el sector que clasifica los reclamos externos.
     *
     * El slug es a la vez la clave del sector en `config/incidencias.php` y el
     * nombre del rol homónimo en Spatie, así que se comparte para las dos cosas.
     */
    public const GARANTIA_CALIDAD = 'garantia_calidad';

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
