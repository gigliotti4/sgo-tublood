<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial de las planillas de objetivos que se subieron: fecha, quién, qué
 * archivo y qué cambió. Es un registro: no se edita ni se borra desde el panel.
 */
class IndicadorCarga extends Model
{
    protected $table = 'indicador_cargas';

    protected $fillable = ['user_id', 'archivo', 'resumen'];

    protected $casts = ['resumen' => 'array'];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
