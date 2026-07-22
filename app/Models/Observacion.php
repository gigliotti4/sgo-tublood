<?php

namespace App\Models;

use App\Observers\ObservacionObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(ObservacionObserver::class)]
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
        'responsable_asignado_at',
        'vence_at',
        'alerta_nivel',
        'sector_id',
        'area_id',
        'contacto_telefono',
        'titulo',
        'descripcion',
        'institucion',
        'provincia',
        'equipamiento',
        'ejecutivo_cuenta',
        'prioridad',
        'tipo_caso',
        'tecnovigilancia',
        'datos_especificos',
    ];

    protected $casts = [
        'tecnovigilancia' => 'boolean',
        'datos_especificos' => 'array',
        'responsable_asignado_at' => 'datetime',
        'vence_at' => 'datetime',
        'alerta_nivel' => 'integer',
    ];

    public function estaFinalizada(): bool
    {
        return in_array($this->estado, config('incidencias.estados_finales', []), true);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ObservationAttachment::class, 'observation_id');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(ObservationProduct::class, 'observation_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Sector de gestión: define los tipos de incidencia disponibles. */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /** Área del organigrama a la que se asignó el caso. */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public static function generarNumero(int $anio): string
    {
        $correlativo = static::where('anio', $anio)->count() + 1;

        return sprintf('%04d-%02d', $correlativo, $anio % 100);
    }
}
