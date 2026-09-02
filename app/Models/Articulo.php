<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Artículo del catálogo de RP Sistemas.
 *
 * Los datos del ERP (código, descripción, stock, agrupaciones...) son de solo
 * lectura: los llena `ArticuloSyncService` y se pisan en cada sincronización.
 * `fecha_vencimiento`, `pm`, `legajo`, `observaciones` y `link_registro` son
 * la excepción: son propios del panel, se cargan a mano o por Excel, y el
 * `upsert()` del sync **no los toca a propósito** — ver el comentario en
 * `ArticuloSyncService`.
 *
 * `proveedor_id` es un caso mixto: sigue siendo propio del panel (el
 * `upsert()` tampoco lo toca), pero lo escriben tres fuentes automáticas —
 * el feed de RP, el import de Excel y el kardex de compras — más la edición
 * manual. `proveedor_origen` guarda cuál de las cuatro lo puso, y
 * `App\Services\VinculacionProveedores` decide cuál puede pisar a cuál: nada
 * automático pisa una corrección hecha a mano.
 *
 * ⚠️ `proveedor_origen` **no es** la columna "Origen" del export, que dice si
 * el artículo vino de RP o lo creó el Excel de Calidad. Son dos preguntas
 * distintas sobre la misma fila.
 *
 * `activo` también lo pisa la sync, aunque no viaje en el body de RP: se
 * deriva de si el artículo vino en el feed de la última corrida. Ver
 * `ArticuloSyncService::sync()`.
 */
class Articulo extends Model
{
    protected $table = 'articulos';

    protected $fillable = [
        'codigo',
        'descripcion',
        'descripcion_adicional',
        'codigo_barras',
        'unidad_medida',
        'codigo_agrupacion_1',
        'descripcion_agrupacion_1',
        'codigo_agrupacion_2',
        'descripcion_agrupacion_2',
        'codigo_agrupacion_3',
        'descripcion_agrupacion_3',
        'stock',
        'stock_disponible',
        'codigo_proveedor',
        'proveedor_id',
        'proveedor_origen',
        'modificado_en',
        'synced_at',
        'activo',
        'fecha_vencimiento',
        'pm',
        'legajo',
        'observaciones',
        'link_registro',
    ];

    protected $casts = [
        'stock' => 'decimal:4',
        'stock_disponible' => 'decimal:4',
        'modificado_en' => 'datetime',
        'synced_at' => 'datetime',
        'activo' => 'boolean',
        'fecha_vencimiento' => 'date',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /**
     * Solo lo que RP sigue sirviendo (o lo que cargó el Excel de Calidad y
     * nunca vino de un feed) — ver ArticuloSyncService para cómo se deriva.
     */
    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('activo', true);
    }

    /**
     * Búsqueda: por código, descripción, código de barras, PM o legajo.
     *
     * Los que empiezan con el término van primero — quien tipea "AGU" busca
     * "AGUJA...", no un artículo que la menciona a mitad de la descripción.
     */
    public function scopeBuscar(Builder $query, string $termino): Builder
    {
        $termino = trim($termino);

        if ($termino === '') {
            return $query;
        }

        return $query
            ->where(fn ($q) => $q
                ->where('codigo', 'like', "%{$termino}%")
                ->orWhere('descripcion', 'like', "%{$termino}%")
                ->orWhere('codigo_barras', 'like', "%{$termino}%")
                ->orWhere('pm', 'like', "%{$termino}%")
                ->orWhere('legajo', 'like', "%{$termino}%"))
            ->orderByRaw('CASE WHEN codigo LIKE ? THEN 0 WHEN descripcion LIKE ? THEN 1 ELSE 2 END', ["{$termino}%", "{$termino}%"])
            ->orderBy('descripcion');
    }
}
