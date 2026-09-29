<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una acción del plan de una No Conformidad (§4.5 y §4.6) — sección 5 del
 * Informe de Desvío: Acción / Responsable / Plazo.
 *
 * Es lo que en el resto del mundo se llama CAPA. El instructivo no tiene una
 * entidad aparte para eso: el plan de acción **es** el CAPA, y
 * `non_conformities.requiere_capa` es la determinación que se toma durante la
 * investigación.
 *
 * ⚠️ Hasta el 24/9/2026 cada acción se clasificaba en corrección / correctiva /
 * preventiva. Se sacó: el formulario real no tiene esa columna y el cliente
 * confirmó que no hacen la distinción, así que era un campo obligatorio que se
 * completaba al azar. No reponerlo sin volver a preguntar.
 */
class NonConformityAction extends Model
{
    public const ESTADOS = [
        'pendiente' => 'Pendiente',
        'en_curso' => 'En curso',
        'completada' => 'Completada',
        'vencida' => 'Vencida',
        'cancelada' => 'Cancelada',
    ];

    /**
     * Las que todavía deben algo.
     *
     * ⚠️ `vencida` cuenta como pendiente: que se haya pasado la fecha no
     * significa que esté hecha — al contrario. Es justamente la que traba el
     * avance a verificación de eficacia (§4.6).
     */
    public const ESTADOS_PENDIENTES = ['pendiente', 'en_curso', 'vencida'];

    protected $table = 'non_conformity_actions';

    protected $fillable = [
        'non_conformity_id',
        'descripcion',
        'responsable_id',
        'fecha_prevista',
        'evidencia_requerida',
        'observaciones',
        'estado',
        'avance',
        'fecha_real',
        'motivo_cancelacion',
    ];

    protected $casts = [
        'fecha_prevista' => 'date',
        'fecha_real' => 'date',
    ];

    public function noConformidad(): BelongsTo
    {
        return $this->belongsTo(NoConformidad::class, 'non_conformity_id');
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    /**
     * Las que traban el avance a verificación de eficacia.
     *
     * Una acción cancelada **no** traba: se canceló con justificación, que es
     * una decisión tomada, no un pendiente.
     */
    public function scopePendientes(Builder $query): Builder
    {
        return $query->whereIn('estado', self::ESTADOS_PENDIENTES);
    }
}
