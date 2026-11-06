<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una persona de un sector, tenga o no usuario del SGO. Alimenta el
 * desplegable "Responsable del fallo" (ver la migración de `personas`).
 *
 * Los inactivos no se ofrecen para elegir, pero se conservan: siguen figurando
 * en las observaciones que ya tenían. Por eso el panel no los borra.
 */
class Persona extends Model
{
    protected $table = 'personas';

    protected $fillable = ['nombre', 'sector_id', 'puesto', 'activo', 'user_id'];

    protected $casts = ['activo' => 'boolean'];

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /** El usuario del SGO de esta persona, si lo tiene. */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Observaciones donde figura como responsable del fallo. Es lo que se
     * muestra en el listado de personal (cuántas lleva) y por lo que no se borra.
     */
    public function observacionesConFallo(): HasMany
    {
        return $this->hasMany(Observacion::class, 'responsable_fallo_id');
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('activo', true);
    }
}
