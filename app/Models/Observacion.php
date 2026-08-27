<?php

namespace App\Models;

use App\Models\Concerns\GuardaAdjuntos;
use App\Observers\ObservacionObserver;
use Closure;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

#[ObservedBy(ObservacionObserver::class)]
class Observacion extends Model
{
    use GuardaAdjuntos, SoftDeletes;

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

    /**
     * `withTrashed()` es obligatorio acá: sin él, borrar una observación libera
     * su lugar en el correlativo y la próxima alta repite un `numero` que es
     * `unique()` en el schema — un 500 en el portal público.
     *
     * Sale del **máximo** y no de `count()`: contar da el número correcto solo
     * mientras la serie no tenga huecos, y un borrado definitivo (fuera del
     * soft delete, que `withTrashed()` sí cubre) deja uno. El formato está
     * zero-padded, así que el máximo alfabético es el máximo numérico.
     */
    public static function generarNumero(int $anio): string
    {
        $ultimo = static::withTrashed()->where('anio', $anio)->max('numero');

        $correlativo = $ultimo ? ((int) substr($ultimo, 0, 4)) + 1 : 1;

        return sprintf('%04d-%02d', $correlativo, $anio % 100);
    }

    /**
     * Da de alta una observación dentro de una transacción, reintentando si dos
     * altas simultáneas se pelean el mismo `numero`.
     *
     * Entre que `generarNumero()` lee el máximo y el INSERT lo escribe hay una
     * ventana en la que otra request puede quedarse con ese número, y `numero`
     * es único: la segunda se cae con violación de integridad. Es raro pero no
     * imposible, y en el portal público el costo es perder un reclamo.
     *
     * El reintento va acá y no en `DB::transaction($cb, $intentos)` porque ese
     * segundo argumento solo reintenta ante errores de concurrencia (deadlocks),
     * y un choque de clave única no lo es: lo relanzaría en el primer intento.
     *
     * @template T
     *
     * @param  Closure(string): T  $alta  Recibe el número asignado.
     * @return T
     */
    public static function altaConNumero(int $anio, Closure $alta, int $intentos = 3)
    {
        for ($intento = 1; ; $intento++) {
            try {
                return DB::transaction(fn () => $alta(static::generarNumero($anio)));
            } catch (QueryException $e) {
                if ($intento >= $intentos || ! static::esChoqueDeNumero($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Si la excepción es el choque del índice único de `numero`.
     *
     * 23000 es "integrity constraint violation" en general (también una FK
     * inválida), así que además se mira que el mensaje nombre la columna. Los
     * dos motores del proyecto la nombran: MySQL en el nombre del índice
     * (`observations_numero_unique`) y SQLite en el de la columna
     * (`observations.numero`).
     */
    private static function esChoqueDeNumero(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000'
            && stripos($e->getMessage(), 'numero') !== false;
    }
}
