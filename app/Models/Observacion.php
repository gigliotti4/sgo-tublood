<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Observacion extends Model
{
    public const ESTADOS = [
        'pendiente_clasificacion' => 'Pendiente de clasificación',
        'clasificada' => 'Clasificada',
        'en_proceso' => 'En proceso',
        'derivada' => 'Derivada',
        'resuelta' => 'Resuelta',
        'cerrada' => 'Cerrada',
        'cancelada' => 'Cancelada',
    ];

    public const ORIGENES = [
        'interna' => 'Interna',
        'externa' => 'Externa',
    ];

    protected $table = 'observations';

    protected $fillable = [
        'numero',
        'anio',
        'tipo',
        'estado',
        'origen',
        'contacto_nombre',
        'contacto_email',
        'contacto_numero_cliente',
        'cliente_id',
        'responsable_id',
        'contacto_telefono',
        'titulo',
        'descripcion',
        'cantidad_afectada',
        'lote',
        'fecha_vencimiento',
        'numero_remito',
        'tipo_comprobante',
        'institucion',
        'provincia',
        'producto',
        'equipamiento',
        'ejecutivo_cuenta',
        'prioridad',
        'tipo_caso',
        'tecnovigilancia',
    ];

    protected $casts = [
        'fecha_vencimiento' => 'date',
        'tecnovigilancia' => 'boolean',
    ];

    public function attachments(): HasMany
    {
        return $this->hasMany(ObservationAttachment::class, 'observation_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function generarNumero(int $anio): string
    {
        $correlativo = static::where('anio', $anio)->count() + 1;

        return sprintf('%04d-%02d', $correlativo, $anio % 100);
    }
}
