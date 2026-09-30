<?php

namespace App\Models;

use App\Models\Concerns\ClasificacionDocumental;
use App\Support\Documentacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 *
 * La clasificación documental (`tipo_proveedor`, el checklist de documentos y
 * los derivados `fecha_vencimiento` / `documentacion_completa`) es la misma que
 * la de Cliente y vive en el trait ClasificacionDocumental, sobre el catálogo
 * compartido de config/documentacion.php.
 *
 * ⚠️ `habilitado` (Sí/No del panel) **no es** `estado` (A/S/I del ERP, que la
 * sincronización pisa). Son dos cosas distintas que en pantalla se parecen.
 *
 * ⚠️ Y `clasificacion_erp` (AGRU_1, lo carga RP y la sync lo pisa) **no es**
 * `tipo_proveedor` (lo elige una persona acá y decide qué documentación se le
 * exige). Dos clasificaciones distintas de la misma empresa; ver
 * config/proveedores.php.
 */
class Proveedor extends Model
{
    use ClasificacionDocumental;

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
        'clasificacion_erp',
        'tipo_proveedor',
        'tiene_legajo',
        'habilitado',
        'fecha_vencimiento',
        'documentacion_completa',
        'modificado_en',
        'synced_at',
    ];

    /**
     * `mails` viaja en las props de Inertia sin que cada controller lo pida.
     *
     * ⚠️ El autocompletado (`ProveedorController::buscar`) selecciona solo tres
     * columnas, así que ahí `mail` no está cargado y el accesor devuelve `[]`.
     * Es inofensivo, pero por eso el accesor tolera que el atributo falte en
     * vez de asumir que siempre viene.
     */
    protected $appends = ['mails'];

    protected $casts = [
        'tiene_legajo' => 'boolean',
        'habilitado' => 'boolean',
        'documentacion_completa' => 'boolean',
        'fecha_vencimiento' => 'date',
        'modificado_en' => 'datetime',
        'synced_at' => 'datetime',
    ];

    /**
     * Los mails del proveedor, uno por elemento.
     *
     * El ERP guarda varias direcciones en la misma celda separadas por punto y
     * coma: 127 de los 373 proveedores con mail tienen más de una, hasta 4
     * (medido el 30/9/2026).
     *
     * ⚠️ **Se parte SOLO por `;`, nunca por espacios ni comas.** No hay una
     * sola coma ni barra en la columna, y partir por espacios rompería al
     * proveedor 176, cuyo mail es `Hugo - Libertador <hlt@...>`: quedarían
     * cuatro pedazos y ninguno sería una dirección. Los espacios que hay son
     * los que siguen al `;`, y de eso se encarga el `trim`.
     *
     * @return Attribute<array<int, string>, never>
     */
    protected function mails(): Attribute
    {
        return Attribute::get(fn (): array => array_values(array_filter(
            array_map('trim', explode(';', (string) ($this->attributes['mail'] ?? '')))
        )));
    }

    public function articulos(): HasMany
    {
        return $this->hasMany(Articulo::class);
    }

    protected function modeloDocumento(): string
    {
        return ProveedorDocumento::class;
    }

    public function tipoDocumental(): ?string
    {
        return $this->tipo_proveedor;
    }

    public function entidadDocumental(): string
    {
        return Documentacion::PROVEEDORES;
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
