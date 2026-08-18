<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un documento del checklist de un cliente. `documento` es la clave del
 * catálogo de config/documentacion.php — ver App\Support\Documentacion.
 */
class ClienteDocumento extends Model
{
    protected $table = 'cliente_documentos';

    protected $fillable = [
        'cliente_id',
        'documento',
        'presentado',
        'fecha_vencimiento',
    ];

    protected $casts = [
        'presentado' => 'boolean',
        'fecha_vencimiento' => 'date',
    ];

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
