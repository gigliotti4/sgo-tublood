<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un documento del checklist de un proveedor. `documento` es la clave del
 * catálogo de config/documentacion.php — ver App\Support\Documentacion.
 *
 * Gemelo de ClienteDocumento: mismo molde, otra tabla padre.
 */
class ProveedorDocumento extends Model
{
    protected $table = 'proveedor_documentos';

    protected $fillable = [
        'proveedor_id',
        'documento',
        'presentado',
        'fecha_vencimiento',
    ];

    protected $casts = [
        'presentado' => 'boolean',
        'fecha_vencimiento' => 'date',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }
}
