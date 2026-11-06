<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un objetivo de gestión: "en este sector, este tipo de fallo no tiene que
 * pasar de X por mes (y se tolera hasta Y)". Alimenta el semáforo del tablero.
 *
 * Es el "OBJETIVOS 2026" del cliente. Se sube desde la planilla
 * (`IndicadorImportService`) o se edita a mano.
 */
class Indicador extends Model
{
    public const MAXIMO = 'maximo';

    public const MINIMO = 'minimo';

    public const TIPOS_META = [
        self::MAXIMO => 'Máximo de fallos',
        self::MINIMO => 'Mínimo a alcanzar',
    ];

    public const ESTADOS = [
        'aprobado' => 'Aprobado',
        'propuesta' => 'Propuesta a aprobar',
        'revisar' => 'Revisar',
    ];

    protected $table = 'indicadores';

    protected $fillable = [
        'sector', 'sector_id', 'fallo', 'origen_dato', 'fuente', 'tipos', 'criterios',
        'meta', 'meta_medicion', 'cant_pedidos', 'objetivo', 'tolerancia', 'tipo_meta',
        'descripcion', 'estado', 'importancia', 'notas', 'vigente_desde', 'vigente_hasta', 'activo',
    ];

    protected $casts = [
        'tipos' => 'array',
        'criterios' => 'array',
        'cant_pedidos' => 'float',
        'objetivo' => 'float',
        'tolerancia' => 'float',
        'importancia' => 'float',
        'vigente_desde' => 'date',
        'vigente_hasta' => 'date',
        'activo' => 'boolean',
    ];

    public function sectorSgo(): BelongsTo
    {
        return $this->belongsTo(Sector::class, 'sector_id');
    }

    public function valores(): HasMany
    {
        return $this->hasMany(IndicadorValor::class);
    }

    /** Si el dato se cuenta solo desde las observaciones del SGO. */
    public function esDelSgo(): bool
    {
        return $this->origen_dato === 'sgo';
    }

    /**
     * ¿Esta observación cuenta para este indicador?
     *
     * Un indicador del SGO cuenta por **tipo** (`tipos`) y, si hace falta partir un
     * tipo en dos indicadores, por **criterios** sobre el título o el motivo:
     * "3.7 sin 0062" y "3.7 retiro sin OC" son el mismo tipo y se distinguen por el
     * título; "6.2 demora" y "6.2 calidad", por el motivo.
     *
     * ⚠️ Un criterio de MOTIVO exige que el caso **tenga** motivo: uno sin motivo
     * (los que se reclasificaron sin saberlo) no se cuenta en ninguno de los dos
     * lados en vez de caer en "calidad" por descarte. Lo recoge el control de
     * calidad de carga.
     *
     * @param  array<string, mixed>|null  $datos  `datos_especificos` de la observación
     */
    public function cuenta(string $tipo, ?string $titulo, ?array $datos): bool
    {
        if (! $this->esDelSgo() || ! in_array($tipo, $this->tipos ?? [], true)) {
            return false;
        }

        $c = $this->criterios ?? [];
        $titulo = mb_strtoupper((string) $titulo);
        $motivo = $datos['motivo'] ?? null;

        if (! empty($c['titulo_contiene']) && ! str_contains($titulo, mb_strtoupper($c['titulo_contiene']))) {
            return false;
        }

        if (! empty($c['titulo_no_contiene']) && str_contains($titulo, mb_strtoupper($c['titulo_no_contiene']))) {
            return false;
        }

        if (! empty($c['motivo']) && ! in_array($motivo, $c['motivo'], true)) {
            return false;
        }

        if (! empty($c['motivo_no']) && ($motivo === null || $motivo === '' || in_array($motivo, $c['motivo_no'], true))) {
            return false;
        }

        return true;
    }
}
