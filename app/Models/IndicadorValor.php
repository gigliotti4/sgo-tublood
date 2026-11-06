<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** El valor de un mes de un indicador que NO sale del SGO (carga manual). */
class IndicadorValor extends Model
{
    protected $table = 'indicador_valores';

    protected $fillable = ['indicador_id', 'mes', 'valor', 'cargado_por'];

    protected $casts = ['valor' => 'float'];

    public function indicador(): BelongsTo
    {
        return $this->belongsTo(Indicador::class);
    }
}
