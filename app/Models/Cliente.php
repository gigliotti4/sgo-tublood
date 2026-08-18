<?php

namespace App\Models;

use App\Models\Concerns\ClasificacionDocumental;
use App\Models\Concerns\GuardaAdjuntos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cliente espejado de RP Sistemas.
 *
 * Los datos del ERP son de solo lectura: los pisa `ClienteSyncService` cada 5
 * minutos. Los campos propios del panel (`tipo_cliente`, `tiene_legajo`,
 * `habilitado`, `notas`, `mail_nuevo`, y los derivados `fecha_vencimiento` /
 * `documentacion_completa`) quedan **fuera** de la lista de columnas de ese
 * `upsert()` a propósito.
 *
 * ⚠️ `fecha_vencimiento` ya no se carga a mano: sale del documento que el
 * catálogo marca como `determina_vencimiento` para el tipo del cliente. Lo
 * escribe {@see static::recalcularEstadoDocumental()}.
 */
class Cliente extends Model
{
    use ClasificacionDocumental, GuardaAdjuntos;

    protected $fillable = [
        'numero',
        'razon_social',
        'nombre_fantasia',
        'cuit',
        'codigo_iva',
        'descripcion_iva',
        'telefono',
        'mail',
        'mail_nuevo',
        'contacto',
        'domicilio',
        'localidad',
        'codigo_provincia',
        'descripcion_provincia',
        'porcen_descuen',
        'usuario_web',
        'codigo_vendedor',
        'nombre_vendedor',
        'codigo_postal',
        'fecha_vencimiento',
        'tipo_cliente',
        'tiene_legajo',
        'habilitado',
        'notas',
        'documentacion_completa',
        'synced_at',
    ];

    protected $casts = [
        'porcen_descuen' => 'decimal:2',
        'fecha_vencimiento' => 'date',
        'tiene_legajo' => 'boolean',
        'habilitado' => 'boolean',
        'documentacion_completa' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function attachments(): HasMany
    {
        return $this->hasMany(ClienteAttachment::class);
    }

    protected function modeloDocumento(): string
    {
        return ClienteDocumento::class;
    }

    public function tipoDocumental(): ?string
    {
        return $this->tipo_cliente;
    }

    /** Los adjuntos van a `clientes/{numero}/` — ver GuardaAdjuntos. */
    protected function carpetaDeAdjuntos(): string
    {
        return 'clientes/'.$this->segmentoSeguro($this->numero, 'sin-numero-'.$this->id);
    }
}
