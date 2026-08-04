<?php

namespace App\Models;

use App\Models\Concerns\GuardaAdjuntos;
use App\Observers\ObservacionObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(ObservacionObserver::class)]
class Observacion extends Model
{
    use GuardaAdjuntos;

    public const ESTADOS = [
        'pendiente_clasificacion' => 'Pendiente de clasificación',
        'clasificada' => 'Clasificada',
        'en_proceso' => 'En proceso',
        'derivada' => 'Derivada',
        'cerrada' => 'Cerrada',
        'cancelada' => 'Cancelada',
    ];

    public const ORIGENES = [
        'interna' => 'Interna',
        'externa' => 'Externa',
    ];

    /**
     * Estados en los que la observación sigue en gestión. Es la definición de
     * "abierta" que comparten el Dashboard y el filtro Abierta/Cerrada del
     * listado (cerrada = cualquier otro estado: cerrada o cancelada).
     */
    public const ESTADOS_ABIERTOS = ['pendiente_clasificacion', 'clasificada', 'en_proceso', 'derivada'];

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
        'created_by',
        'responsable_asignado_at',
        'vence_at',
        'alerta_nivel',
        'sector_id',
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

    /**
     * Casos abiertos que esta persona gestiona.
     *
     * Vive acá y no en el middleware porque lo consultan dos lugares: el modal
     * de avisos (para mostrarlos) y NotificacionController (para saber cuáles
     * marcar como vistos al cerrarlo).
     */
    public function scopeACargoDe(Builder $query, User $user): Builder
    {
        return $query->whereIn('estado', self::ESTADOS_ABIERTOS)
            ->where('responsable_id', $user->id);
    }

    /**
     * Casos abiertos que esta persona sigue sin gestionar: está en la lista de
     * notificados y no es la responsable.
     *
     * El `orWhereNull` no es defensivo, hace falta: en SQL `responsable_id != X`
     * da NULL (falsy) cuando la columna es NULL, así que sin esa rama se caerían
     * del listado justo los casos sin responsable asignado — que son los que más
     * necesitan que alguien los mire.
     */
    public function scopeSeguidasPor(Builder $query, User $user): Builder
    {
        return $query->whereIn('estado', self::ESTADOS_ABIERTOS)
            ->whereHas('notificados', fn ($q) => $q->whereKey($user->id))
            ->where(fn ($q) => $q->whereNull('responsable_id')->orWhere('responsable_id', '!=', $user->id));
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ObservationAttachment::class, 'observation_id');
    }

    /** Bitácora del caso: comentarios y cambios registrados por ObservacionObserver. */
    public function historial(): HasMany
    {
        return $this->hasMany(ObservationHistory::class, 'observation_id');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(ObservationProduct::class, 'observation_id');
    }

    /**
     * Usuarios a notificar, además del responsable: reciben el aviso al ser
     * sumados y pueden comentar en la bitácora, pero no gestionar el caso.
     */
    public function notificados(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'observation_watchers', 'observation_id', 'user_id')
            ->withTimestamps();
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Quién la cargó desde el panel; null si entró por el portal público. */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Sector de gestión: define los tipos de incidencia disponibles. */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /** Los adjuntos van a `observaciones/{numero}/` — ver GuardaAdjuntos. */
    protected function carpetaDeAdjuntos(): string
    {
        return 'observaciones/'.$this->segmentoSeguro($this->numero, 'sin-numero-'.$this->id);
    }

    public static function generarNumero(int $anio): string
    {
        $correlativo = static::where('anio', $anio)->count() + 1;

        return sprintf('%04d-%02d', $correlativo, $anio % 100);
    }
}
