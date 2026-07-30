<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Artículo del catálogo de RP Sistemas.
 *
 * Es un espejo de solo lectura: lo llena `ArticuloSyncService` y no se edita
 * desde el panel. Sin precios — ver la migración.
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
        'modificado_en',
        'synced_at',
    ];

    protected $casts = [
        'stock' => 'decimal:4',
        'stock_disponible' => 'decimal:4',
        'modificado_en' => 'datetime',
        'synced_at' => 'datetime',
    ];

    /**
     * Búsqueda del selector: por código, descripción o código de barras.
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
                ->orWhere('codigo_barras', 'like', "%{$termino}%"))
            ->orderByRaw('CASE WHEN codigo LIKE ? THEN 0 WHEN descripcion LIKE ? THEN 1 ELSE 2 END', ["{$termino}%", "{$termino}%"])
            ->orderBy('descripcion');
    }
}
