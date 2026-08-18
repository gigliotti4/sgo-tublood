<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Proveedor del padrón, espejado de `powerbi_proveedores_vista` del ERP.
 *
 * Todo lo que llena `ProveedorSyncService` se pisa en cada sincronización. La
 * única excepción es `observaciones`: es propio del panel y queda fuera de la
 * lista de columnas del `upsert()` a propósito.
 *
 * `numero` es opcional porque hay una segunda puerta de entrada: el Excel de
 * artículos, que trae la razón social pero no el NUM_PROV. Los que entran por
 * ahí quedan sin número hasta que el sync los adopte por razón social.
 */
class Proveedor extends Model
{
    /** Laravel pluralizaría a `proveedors`. */
    protected $table = 'proveedores';

    protected $fillable = [
        'numero',
        'razon_social',
        'nombre_fantasia',
        'domicilio',
        'cuit',
        'telefono',
        'celular',
        'mail',
        'localidad',
        'provincia',
        'codigo_postal',
        'contacto',
        'observaciones',
        'estado',
        'modificado_en',
        'synced_at',
    ];

    protected $casts = [
        'modificado_en' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function articulos(): HasMany
    {
        return $this->hasMany(Articulo::class);
    }

    /**
     * Forma canónica de una razón social: solo letras y números, en mayúscula
     * y sin acentos.
     *
     * Es la clave con la que los dos imports reconocen al mismo proveedor,
     * porque cada planilla lo escribe distinto ("PROPATO HNOS. S.A.I.C." vs
     * "PROPATO HNOS S A I C"). Vive acá y no en un servicio porque la usan
     * `ArticuloImportService` (para asignar el proveedor de un artículo) y
     * `ProveedorImportService` (para adoptar los que quedaron sin número):
     * si se desincronizaran, un proveedor entraría dos veces.
     *
     * El matcheo sobre esta forma es **exacto, nunca aproximado**: un parecido
     * no alcanza para decidir de quién es un artículo.
     */
    public static function normalizarRazonSocial(string $valor): string
    {
        return (string) preg_replace(
            '/[^A-Z0-9]/',
            '',
            (string) Str::of($valor)->ascii()->upper()
        );
    }

    /**
     * Búsqueda: por número, razón social, domicilio, CUIT o localidad.
     *
     * Los que empiezan con el término van primero — quien tipea "CRONO" busca
     * "CRONOINK SRL", no un proveedor que lo menciona a mitad del domicilio.
     */
    public function scopeBuscar(Builder $query, string $termino): Builder
    {
        $termino = trim($termino);

        if ($termino === '') {
            return $query;
        }

        return $query
            ->where(fn ($q) => $q
                ->where('numero', 'like', "%{$termino}%")
                ->orWhere('razon_social', 'like', "%{$termino}%")
                ->orWhere('domicilio', 'like', "%{$termino}%")
                ->orWhere('cuit', 'like', "%{$termino}%")
                ->orWhere('localidad', 'like', "%{$termino}%"))
            ->orderByRaw('CASE WHEN numero LIKE ? THEN 0 WHEN razon_social LIKE ? THEN 1 ELSE 2 END', ["{$termino}%", "{$termino}%"])
            ->orderBy('razon_social');
    }
}
