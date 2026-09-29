<?php

namespace App\Models;

use App\Models\Concerns\GeneraNumeroCorrelativo;
use App\Models\Concerns\GuardaAdjuntos;
use App\Observers\ObservacionObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(ObservacionObserver::class)]
class Observacion extends Model
{
    use GeneraNumeroCorrelativo, GuardaAdjuntos, SoftDeletes;

    public const ESTADOS = [
        'pendiente_clasificacion' => 'Pendiente de clasificación',
        'clasificada' => 'Clasificada',
        'en_proceso' => 'En proceso',
        'derivada' => 'Derivada',
        // ⚠️ NO confundir con `derivada`, que es la derivación entre SECTORES
        // (todavía pendiente). Ésta es el escalamiento a una No Conformidad:
        // la observación se investiga dentro del desvío y desde acá solo se
        // navega. Se cierra a mano, por separado — decisión del cliente del
        // 28/9/2026 — y por eso el desvío ya no exige que esté cerrada para
        // poder cerrarse (ver `NoConformidad::condicionesDeCierre()`): si lo
        // exigiera, los dos se esperarían para siempre.
        'derivada_nc' => 'Derivada a No Conformidad',
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
    public const ESTADOS_ABIERTOS = ['pendiente_clasificacion', 'clasificada', 'en_proceso', 'derivada', 'derivada_nc'];

    /**
     * Bandera en memoria (no es columna): la usa el alta del portal para que el
     * observer no mande el aviso de asignación.
     *
     * El reclamo externo entra ya asignado según el tipo, y a esa persona
     * `Portal\ObservacionController::avisar()` le manda el aviso de "reclamo
     * nuevo". Sin esto recibiría además el de "te asignaron", que es el mismo
     * hecho contado dos veces.
     *
     * No alcanza con mirar `origen === 'externa'` en el observer: una externa
     * cargada a mano desde el panel también elige responsable, y ahí el aviso
     * de asignación sí corresponde. Lo que distingue los dos casos es quién
     * asignó, no de dónde viene el reclamo.
     */
    public bool $omitirAvisoDeAsignacion = false;

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
        'cerrada_at',
        'cerrada_por',
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
        'datos_especificos',
    ];

    protected $casts = [
        'datos_especificos' => 'array',
        'responsable_asignado_at' => 'datetime',
        'vence_at' => 'datetime',
        'cerrada_at' => 'datetime',
        'alerta_nivel' => 'integer',
    ];

    public function estaFinalizada(): bool
    {
        return in_array($this->estado, config('incidencias.estados_finales', []), true);
    }

    /**
     * Texto para mostrar en vez del estado crudo. "Clasificada" no dice nada
     * de la urgencia del caso, así que en ese estado se muestra la prioridad
     * asignada; el resto de los estados usa ESTADOS tal cual.
     *
     * Mismo criterio que `resources/js/lib/estados.ts` en el front (usado en
     * el listado y el detalle) — mantenerlos sincronizados si cambia la regla.
     * Lo usan el PDF y el Excel, que no pueden reusar el helper de TypeScript.
     */
    public function etiquetaEstado(): string
    {
        if ($this->estado === 'clasificada' && $this->prioridad) {
            return config('incidencias.prioridades')[$this->prioridad] ?? self::ESTADOS['clasificada'];
        }

        return self::ESTADOS[$this->estado] ?? $this->estado;
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

    /** La última baja registrada (cancelación o borrado), para mostrar su motivo sin pegarle a toda la bitácora. */
    public function baja(): HasOne
    {
        return $this->hasOne(ObservationHistory::class, 'observation_id')
            ->where('accion', ObservationHistory::ACCION_BAJA)
            ->latestOfMany();
    }

    /**
     * Quién cerró el caso.
     *
     * Columna propia y no una lectura de la bitácora — ver
     * `ObservacionObserver::marcarCierre()`. La escribe solo el observer.
     *
     * ⚠️ Se llama `cerradaPorUsuario` y no `cerradaPor` a propósito: Eloquent
     * serializaría esta última como `cerrada_por`, que es el nombre de la
     * columna `int`, y la clave del JSON quedaría con el objeto o con el id
     * según el orden de serialización.
     */
    public function cerradaPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrada_por');
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

    /**
     * Los desvíos a los que se vinculó esta observación (§5).
     *
     * Es el inverso de `NoConformidad::observaciones()`, sobre el mismo pivot.
     * Son varios a propósito: una observación puede escalar a un desvío y ese
     * desvío resultar ineficaz, abriendo el que lo reemplaza — los dos siguen
     * hablando de esta observación.
     */
    public function noConformidades(): BelongsToMany
    {
        return $this->belongsToMany(NoConformidad::class, 'non_conformity_observation', 'observation_id', 'non_conformity_id')
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
}
