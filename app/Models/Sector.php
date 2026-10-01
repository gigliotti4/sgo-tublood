<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sector extends Model
{
    /**
     * Garantía de Calidad, el sector que clasifica los reclamos externos.
     *
     * El slug es a la vez la clave del sector en `config/incidencias.php` y el
     * nombre del rol homónimo en Spatie, así que se comparte para las dos cosas.
     */
    public const GARANTIA_CALIDAD = 'garantia_calidad';

    protected $fillable = [
        'nombre',
        'slug',
        'dias_gestion',
        'tope_observaciones',
        // ⚠️ Tiene que estar acá aunque no sea un campo del panel: lo escribe
        // `sectores:tope` con `update()`, y sin el fillable Eloquent lo descarta
        // en silencio — la marca nunca se sella y el aviso sale en cada corrida.
        // `SectorController::validar()` no lo acepta, así que no entra por el
        // formulario. Misma trampa que `verificacion_avisada_at` en las NC.
        'tope_avisado_at',
        'activo',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'dias_gestion' => 'integer',
        'tope_observaciones' => 'integer',
        'tope_avisado_at' => 'datetime',
    ];

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Las observaciones derivadas a este sector.
     *
     * ⚠️ Es `observations.sector_id` —a dónde se derivó el caso— y **no** el
     * sector del responsable, que es de donde sale el plazo de vencimiento
     * (ver `ObservacionObserver::saving()`). El código ya usa las dos nociones
     * en paralelo: el Dashboard agrupa por ésta y el reloj mide por la otra.
     * Para "cuánto trabajo tiene esta área" manda ésta.
     */
    public function observaciones(): HasMany
    {
        return $this->hasMany(Observacion::class);
    }

    /**
     * Cuántas observaciones abiertas tiene el sector ahora mismo.
     *
     * "Abierta" es `Observacion::ESTADOS_ABIERTOS`, la misma definición que
     * comparten el Dashboard y el filtro del listado — no una lista propia, que
     * se desincronizaría el día que se agregue un estado.
     */
    public function observacionesAbiertas(): int
    {
        return $this->observaciones()
            ->whereIn('estado', Observacion::ESTADOS_ABIERTOS)
            ->count();
    }

    /**
     * ⚠️ El umbral es **estricto**: un tope de 5 se supera recién con 6.
     *
     * "Aguanta 5 observaciones" quiere decir que 5 está bien. Es la clase de
     * off-by-one que después nadie se anima a tocar, así que hay un test que lo
     * fija en los dos bordes.
     *
     * Sin tope cargado (`null`) nunca supera nada: es el estado inicial de los
     * diez sectores y lo que hace que esto no moleste hasta que alguien lo use.
     */
    public function superaElTope(?int $abiertas = null): bool
    {
        if ($this->tope_observaciones === null) {
            return false;
        }

        return ($abiertas ?? $this->observacionesAbiertas()) > $this->tope_observaciones;
    }
}
