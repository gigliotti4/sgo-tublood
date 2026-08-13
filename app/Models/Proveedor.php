<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Proveedor del padrón.
 *
 * `numero`, `razon_social` y `domicilio` son lo que trae la planilla Excel y se
 * actualizan en cada importación; el resto son campos propios del panel que el
 * import no toca (ver `ProveedorImportService`).
 *
 * `numero` es opcional porque hay dos puertas de entrada: el padrón (que lo
 * trae) y el Excel de artículos (que solo trae la razón social). Los que entran
 * por la segunda quedan sin número hasta que el padrón los adopte.
 */
class Proveedor extends Model
{
    /** Laravel pluralizaría a `proveedors`. */
    protected $table = 'proveedores';

    protected $fillable = [
        'numero',
        'razon_social',
        'domicilio',
        'cuit',
        'telefono',
        'mail',
        'localidad',
        'observaciones',
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
