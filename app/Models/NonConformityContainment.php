<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una acción inmediata de contención — sección 3 del Informe de Desvío.
 *
 * Es lo que se hizo apenas se detectó el problema para frenarlo, **antes** de
 * conocer la causa. No confundir con `NonConformityAction`: aquélla es el plan
 * de acción (§4.5), que ataca la causa una vez analizada y tiene su propio
 * ciclo de vida con estados, avances y evidencias. Ésta no tiene estado: o se
 * hizo, y se registra, o no existe.
 *
 * Molde de `NonConformityAction` y del resto de las tablas hijas del dominio.
 */
class NonConformityContainment extends Model
{
    protected $table = 'non_conformity_containments';

    protected $fillable = [
        'non_conformity_id',
        'fecha',
        'accion',
        'responsable_id',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function noConformidad(): BelongsTo
    {
        return $this->belongsTo(NoConformidad::class, 'non_conformity_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }
}
