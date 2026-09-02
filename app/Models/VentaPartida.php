<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lote despachado en un renglón de un comprobante de venta.
 *
 * Es de **solo lectura desde el panel**: no hay alta, edición ni borrado. Toda
 * la tabla se reemplaza en cada sincronización (ver `VentaPartidaSyncService`),
 * así que cualquier cosa que se escriba acá se pierde en la corrida siguiente.
 *
 * Una fila por (comprobante, artículo, lote) — ver la migración para por qué el
 * join va por `compro_nro` y no por el número de remito.
 */
class VentaPartida extends Model
{
    protected $table = 'venta_partidas';

    protected $fillable = [
        'compro_nro',
        'cod_comprobante',
        'numero',
        'codigo_articulo',
        'codigo_partida',
        'cantidad',
        'remito_tipo',
        'remito_numero',
        'fecha',
        'synced_at',
    ];

    /** `remito` es derivado (tipo + número) y tiene que viajar en las props. */
    protected $appends = ['remito'];

    protected $casts = [
        'numero' => 'integer',
        'cantidad' => 'decimal:4',
        'remito_numero' => 'integer',
        'fecha' => 'date',
        'synced_at' => 'datetime',
    ];

    /**
     * Solo de lectura, sin constraint en base: el ERP puede nombrar un artículo
     * que todavía no sincronizamos. Mismo criterio que `Partida::articulo()`.
     */
    public function articulo(): BelongsTo
    {
        return $this->belongsTo(Articulo::class, 'codigo_articulo', 'codigo');
    }

    /**
     * La partida del padrón. Ojo: la clave real es compuesta (artículo + lote),
     * así que este `belongsTo` por lote solo es confiable cuando ya se filtró
     * por artículo. Para resolver desde cero usar `Partida::adjuntarAProductos()`.
     */
    public function partida(): BelongsTo
    {
        return $this->belongsTo(Partida::class, 'codigo_partida', 'codigo_partida');
    }

    /**
     * Series de remito del ERP.
     *
     * ⚠️ **`PRECE_*` no siempre es un remito.** Es "el documento que precedió a
     * este movimiento", y en 65.686 de las filas (39%) es un movimiento de
     * stock (`STO`/`STI`), no un remito: medido, coincide con
     * `ventas.remito_nro` en 0 de 158 casos. Solo las series `VR*` son remitos
     * — ahí la coincidencia es de 14.486 sobre 14.486 (100%) tomando los
     * comprobantes con un solo remito de cada lado.
     */
    public const SERIES_DE_REMITO = ['VR8', 'VRM', 'VR6'];

    /**
     * El remito con su serie (`VR8-8500`), que es la única forma de nombrarlo
     * sin ambigüedad: 7.395 de 25.320 números (29%) se repiten entre series.
     *
     * Devuelve `null` cuando el documento precedente no es un remito, para que
     * la pantalla caiga al `remito_nro` que ya trae `ventas` en vez de mostrar
     * un número de stock rotulado como remito.
     */
    public function getRemitoAttribute(): ?string
    {
        if (! $this->remito_numero || ! in_array($this->remito_tipo, self::SERIES_DE_REMITO, true)) {
            return null;
        }

        return "{$this->remito_tipo}-{$this->remito_numero}";
    }

    /**
     * Cuelga de cada venta los lotes que se despacharon en ese renglón, como
     * relación `lotes`, para que viajen en las props de Inertia.
     *
     * Va acá y no en una relación de Eloquent porque la clave es compuesta
     * (`compro_nro` + `articulo`) y `belongsTo` no sabe hacer eso — menos aún
     * con eager loading. Resuelve **todos** los renglones en una sola query.
     * Mismo patrón que `Partida::adjuntarAProductos()`.
     *
     * ⚠️ La relación se setea **siempre**, aunque sea con una colección vacía:
     * si no, Inertia no manda la clave y el front recibe `undefined` en vez de
     * "este renglón no tiene lote". El 11% de los renglones no lo tiene (son
     * artículos sin trazabilidad de lote: servicios, ajustes de cambio), así
     * que vacío es un resultado normal y no un error.
     *
     * @param  iterable<int, Venta>  $ventas
     */
    public static function adjuntarAVentas(iterable $ventas): void
    {
        $ventas = collect($ventas);

        $compros = $ventas->pluck('compro_nro')->filter()->unique()->values();

        $porClave = $compros->isEmpty()
            ? collect()
            : self::query()
                ->whereIn('compro_nro', $compros->all())
                ->orderBy('codigo_partida')
                ->get()
                ->groupBy(fn (self $vp) => self::clave($vp->compro_nro, $vp->codigo_articulo));

        foreach ($ventas as $venta) {
            $clave = self::clave($venta->compro_nro, $venta->articulo);

            $venta->setRelation('lotes', $porClave->get($clave, collect())->values());
        }
    }

    /**
     * Clave del renglón: comprobante + artículo.
     *
     * El artículo se normaliza a mayúsculas por el mismo motivo que en
     * `Partida::normalizarCodigo()` — el ERP mezcla mayúsculas y minúsculas en
     * los códigos (`re-1428` y `RE-1428` son el mismo artículo).
     */
    private static function clave(?string $compro, ?string $articulo): string
    {
        return trim((string) $compro)."\0".mb_strtoupper(trim((string) $articulo));
    }

    /**
     * Ventas que despacharon una partida, para la ficha de la partida.
     *
     * Se filtra por artículo **y** lote porque el código de lote solo es único
     * dentro de un artículo: 885 lotes se repiten entre artículos distintos.
     */
    public function scopeDeLaPartida(Builder $query, Partida $partida): Builder
    {
        return $query
            ->where('codigo_articulo', $partida->codigo_articulo)
            ->where('codigo_partida', $partida->codigo_partida);
    }
}
