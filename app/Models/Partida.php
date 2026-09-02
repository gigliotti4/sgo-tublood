<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Partida (lote) espejada de `COMPRO_PARTIDAS` del ERP.
 *
 * Es de **solo lectura desde el panel**: no hay alta, edición ni borrado. Toda
 * la tabla se reemplaza en cada sincronización (ver `PartidaSyncService`), así
 * que cualquier cosa que se escriba acá se pierde en la corrida siguiente.
 *
 * Una fila por (artículo, partida) — ver la migración para por qué la clave es
 * compuesta y por qué no se guarda ningún saldo de stock.
 */
class Partida extends Model
{
    protected $table = 'partidas';

    protected $fillable = [
        'codigo_articulo',
        'codigo_partida',
        'fecha_vencimiento',
        'proveedor_numero',
        'ubicacion',
        'ultimo_movimiento_at',
        'synced_at',
    ];

    protected $casts = [
        'fecha_vencimiento' => 'date',
        'ultimo_movimiento_at' => 'date',
        'synced_at' => 'datetime',
    ];

    /**
     * Solo de lectura, sin constraint en base: el ERP puede nombrar un artículo
     * que todavía no sincronizamos. Mismo criterio que
     * `ObservationProduct::articulo()`.
     */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'codigo_articulo', 'codigo');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class, 'proveedor_numero', 'numero');
    }

    /**
     * Búsqueda del listado: lote, código de artículo, descripción del artículo
     * o razón social del proveedor.
     *
     * El lote se normaliza igual que en la atribución (mayúsculas, sin espacios
     * en los bordes) para que buscar `atdec24` encuentre `ATDEC24090046`. Los
     * otros campos van con `like` común porque el usuario está buscando texto,
     * no cruzando una clave.
     */
    public function scopeBuscar(Builder $query, string $termino): Builder
    {
        $termino = trim($termino);

        if ($termino === '') {
            return $query;
        }

        $like = '%'.$termino.'%';

        return $query->where(function (Builder $q) use ($like, $termino) {
            $q->where('codigo_partida', 'like', '%'.mb_strtoupper($termino).'%')
                ->orWhere('codigo_articulo', 'like', $like)
                ->orWhereHas('articulo', fn (Builder $a) => $a->where('descripcion', 'like', $like))
                ->orWhereHas('proveedor', fn (Builder $p) => $p->where('razon_social', 'like', $like));
        });
    }

    /**
     * Estado del vencimiento de la partida.
     *
     * ⚠️ `sin_vencimiento` no es lo mismo que "no vence": 1.141 de las 12.780
     * partidas no traen `FECHA_VENCI` cargada en ningún movimiento del kardex,
     * que es un dato faltante del ERP y no una propiedad del producto. Por eso
     * es un filtro propio y no se mezcla con las vigentes.
     */
    public function scopeVencimiento(Builder $query, string $estado): Builder
    {
        return match ($estado) {
            'vencidas' => $query->whereNotNull('fecha_vencimiento')
                ->whereDate('fecha_vencimiento', '<', now()),
            'por_vencer' => $query->whereNotNull('fecha_vencimiento')
                ->whereDate('fecha_vencimiento', '>=', now())
                ->whereDate('fecha_vencimiento', '<=', now()->addDays(90)),
            'vigentes' => $query->whereNotNull('fecha_vencimiento')
                ->whereDate('fecha_vencimiento', '>', now()->addDays(90)),
            'sin_vencimiento' => $query->whereNull('fecha_vencimiento'),
            default => $query,
        };
    }

    /**
     * Forma normalizada de un código, para cruzar el lote que tipeó un cliente
     * en el portal contra el padrón.
     *
     * ⚠️ Solo mayúsculas y sin espacios en los bordes: el ERP guarda 1.866
     * movimientos con el lote en minúscula y 31 con espacios sobrantes, pero
     * los códigos de lote **no** se normalizan más allá de eso. A diferencia de
     * `Proveedor::normalizarRazonSocial()`, acá no se sacan guiones ni puntos:
     * `20260522-582` y `20260522582` son lotes potencialmente distintos, y
     * atribuirle a un reclamo la partida equivocada es peor que no atribuirle
     * ninguna.
     */
    public static function normalizarCodigo(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : mb_strtoupper($valor);
    }

    /**
     * Resuelve la partida de cada producto reclamado y la deja colgada como
     * relación `partida`, para que viaje en las props de Inertia.
     *
     * Va acá y no en una relación de Eloquent porque la clave es compuesta
     * (`codigo` + `lote`) y `belongsTo` no sabe hacer eso — menos aún con eager
     * loading. Resuelve **todos** los productos en una sola query.
     *
     * Se lee **en vivo del padrón**, nunca de una copia guardada en la
     * observación: completar una partida en el ERP reencuadra también los
     * reclamos ya cargados. Mismo criterio que el proveedor del artículo.
     *
     * ⚠️ Si el lote matchea la misma partida en más de un artículo y el
     * producto no trae un `codigo` que desempate, no se atribuye ninguna: 885
     * códigos de lote se repiten entre artículos y elegir uno al azar mandaría
     * a revisar el artículo equivocado.
     *
     * @param  iterable<int, ObservationProduct>  $productos
     */
    public static function adjuntarAProductos(iterable $productos): void
    {
        $productos = collect($productos);

        $lotes = $productos
            ->map(fn (ObservationProduct $p) => self::normalizarCodigo($p->lote))
            ->filter()
            ->unique()
            ->values();

        // Por lote: todas las partidas que lo comparten, para poder desempatar
        // por artículo (y detectar cuándo no hay forma de desempatar).
        //
        // ⚠️ Sin lotes que buscar no se saltea el `foreach`: la relación tiene
        // que quedar seteada igual, en `null`. Si no, Inertia no manda la clave
        // y el front recibe `undefined` en vez de "no se pudo atribuir".
        $porLote = $lotes->isEmpty()
            ? collect()
            : self::query()
                ->with(['proveedor:id,numero,razon_social', 'articulo:codigo,descripcion'])
                ->whereIn('codigo_partida', $lotes->all())
                ->get()
                ->groupBy(fn (Partida $p) => self::normalizarCodigo($p->codigo_partida));

        foreach ($productos as $producto) {
            $producto->setRelation('partida', self::elegir($porLote, $producto));
        }
    }

    /**
     * @param  Collection<string, Collection<int, Partida>>  $porLote
     */
    private static function elegir(Collection $porLote, ObservationProduct $producto): ?Partida
    {
        $lote = self::normalizarCodigo($producto->lote);

        if ($lote === null || ! $porLote->has($lote)) {
            return null;
        }

        $opciones = $porLote->get($lote);
        $codigo = self::normalizarCodigo($producto->codigo);

        if ($codigo !== null) {
            return $opciones->first(
                fn (Partida $p) => self::normalizarCodigo($p->codigo_articulo) === $codigo
            );
        }

        // Sin código de artículo solo sirve si el lote es inequívoco.
        return $opciones->count() === 1 ? $opciones->first() : null;
    }
}
