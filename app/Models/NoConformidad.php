<?php

namespace App\Models;

use App\Models\Concerns\GeneraNumeroCorrelativo;
use App\Models\Concerns\GuardaAdjuntos;
use App\Observers\NoConformidadObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * No Conformidad: un incumplimiento confirmado que requiere investigación,
 * análisis de causa, acciones y verificación de eficacia.
 *
 * Ver `docs/IT- PARA LA GESTIÓN DE NO CONFORMIDADES EN EL SISTEMA.docx`.
 *
 * ⚠️ No confundir "NC" con **Nota de Crédito**, que es como la llama
 * `Especificacion-Tecnica-SGO-v3.docx` §5.5 y §9.1 al hablar del ERP. Acá NC es
 * siempre No Conformidad.
 */
#[ObservedBy(NoConformidadObserver::class)]
class NoConformidad extends Model
{
    use GeneraNumeroCorrelativo, GuardaAdjuntos, SoftDeletes;

    /**
     * Estados del flujo (§4 y el flujograma de §4.1).
     *
     * ⚠️ "Pendiente de evaluación" **no** es un estado: es uno de los
     * resultados posibles de la verificación de eficacia (§4.7). La NC se queda
     * en `verificacion_eficacia` hasta que pase el tiempo necesario para
     * evaluarla.
     */
    public const ESTADOS = [
        'borrador' => 'Borrador',
        'pendiente_aprobacion' => 'Pendiente de aprobación',
        'abierta' => 'Abierta / En investigación',
        'plan_accion' => 'Plan de acción',
        'en_implementacion' => 'En implementación',
        'verificacion_eficacia' => 'Verificación de eficacia',
        'cerrada' => 'Cerrada',
        'rechazada' => 'Rechazada',
        'cancelada' => 'Cancelada',
    ];

    /**
     * Estados en los que la NC sigue viva. Lo consume el KPI `ncAbiertas` del
     * Dashboard.
     *
     * `borrador` **cuenta como abierta**: todavía no es una NC formal, pero es
     * trabajo pendiente de alguien y esconderla la haría desaparecer del radar.
     */
    public const ESTADOS_ABIERTOS = [
        'borrador',
        'pendiente_aprobacion',
        'abierta',
        'plan_accion',
        'en_implementacion',
        'verificacion_eficacia',
    ];

    /** Terminales: no se sale de acá salvo reapertura (solo super-admin, §4.8). */
    public const ESTADOS_FINALES = ['cerrada', 'rechazada', 'cancelada'];

    /**
     * Transiciones permitidas, tal cual el flujograma de §4.1.
     *
     * Es el catálogo que valida el controller. No incluye la reapertura de una
     * NC cerrada ni la cancelación: las dos son excepciones de super-admin y se
     * tratan aparte, justamente para que no parezcan parte del flujo normal.
     */
    public const TRANSICIONES = [
        'borrador' => ['pendiente_aprobacion'],
        'pendiente_aprobacion' => ['abierta', 'borrador', 'rechazada'],
        'abierta' => ['plan_accion'],
        'plan_accion' => ['en_implementacion'],
        'en_implementacion' => ['verificacion_eficacia'],
        // ⚠️ Un resultado ineficaz NO reabre esta NC: abre una nueva que la
        // reemplaza (sección 7 del formulario, "Nuevo desvío N°"). Ésta se
        // cierra igual, con su fecha y su responsable de cierre. Por eso acá no
        // hay vuelta a `abierta` — §4.7 del instructivo decía lo contrario y el
        // cliente confirmó el formulario el 24/9/2026.
        'verificacion_eficacia' => ['cerrada'],
        'cerrada' => [],
        'rechazada' => [],
        'cancelada' => [],
    ];

    public const TIPOS_DESVIO = [
        'interno' => 'Interno',
        'externo' => 'Externo',
    ];

    /**
     * Resultado de la verificación de eficacia (§4.7).
     *
     * ⚠️ `pendiente_evaluacion` **no** es un estado de la NC: es un resultado
     * que dice "todavía no pasó el tiempo necesario para saberlo". La NC se
     * queda en `verificacion_eficacia` esperando.
     */
    public const RESULTADOS_EFICACIA = [
        'eficaz' => 'Eficaz',
        'ineficaz' => 'Ineficaz',
        'pendiente_evaluacion' => 'Pendiente de evaluación',
    ];

    /**
     * Los seis factores del análisis de causa raíz (sección 4 del formulario),
     * el clásico Ishikawa de las 6M.
     *
     * Se guardan en `causa_raiz_factores` como `{factor: texto}` y solo los que
     * contribuyeron: de un diagrama de causa-efecto lo que sirve es *qué* aportó
     * cada factor, no cuáles se tildaron.
     *
     * ⚠️ El orden es el del formulario en papel (Entorno tercero, no último), y
     * es el que dibuja la pantalla: el front itera este objeto tal cual.
     */
    public const FACTORES_CAUSA = [
        'hombre' => 'Hombre',
        'maquina' => 'Máquina',
        'entorno' => 'Entorno',
        'material' => 'Material',
        'metodo' => 'Método',
        'medida' => 'Medida',
    ];

    protected $table = 'non_conformities';

    protected $fillable = [
        'numero',
        'anio',
        'estado',
        'tipo_desvio',
        'fecha_deteccion',
        'motivo',
        'sector_id',
        'cliente_id',
        'proveedor_id',
        'descripcion',
        'creado_por',
        'responsable_id',
        'aprobada_por',
        'aprobada_at',
        'cerrada_por',
        'cerrada_at',
        'reemplaza_a_id',
        // Investigación (§4.4)
        'investigacion',
        'alcance',
        'afectados',
        'evaluacion_riesgo',
        'es_grave',
        'es_repetitivo',
        'requiere_capa',
        // Se carga al armar el plan de acción (§4.5), no en la sección 6: es lo
        // que permite avisar ANTES de llegar a la etapa de verificación.
        'fecha_verificacion_prevista',
        // La escribe `nc:recordatorios` para no avisar dos veces, y la limpia
        // el controller cuando alguien mueve la fecha. Va en fillable por lo
        // segundo: sin eso el `update()` no persiste y el aviso no vuelve a
        // salir en la fecha nueva.
        'verificacion_avisada_at',
        'causa_raiz',
        'causa_raiz_factores',
        'conclusion',
        // Verificación de eficacia (§4.7)
        'metodo_seguimiento',
        'fecha_seguimiento',
        'evidencia_revisada',
        'resultado_eficacia',
        'observaciones_verificacion',
        // Cierre (§4.8)
        'resultado_final',
        'observaciones_finales',
    ];

    protected $casts = [
        'fecha_deteccion' => 'date',
        'aprobada_at' => 'datetime',
        'cerrada_at' => 'datetime',
        'es_grave' => 'boolean',
        'es_repetitivo' => 'boolean',
        'requiere_capa' => 'boolean',
        'causa_raiz_factores' => 'array',
        // ⚠️ Dos fechas parecidas que NO son lo mismo: `prevista` es cuándo se
        // va a verificar (a futuro, se carga con el plan) y `seguimiento` es
        // cuándo se verificó (registro, sección 6).
        'fecha_verificacion_prevista' => 'date',
        'fecha_seguimiento' => 'date',
        'verificacion_avisada_at' => 'datetime',
    ];

    /** El correlativo lleva prefijo: `NC-0001-26`. */
    protected static function prefijoDeNumero(): string
    {
        return 'NC-';
    }

    /**
     * Los adjuntos van a `no-conformidades/{numero}/` — ver GuardaAdjuntos.
     *
     * Una NC sin aprobar todavía no tiene número, así que cae al id. No se
     * renombra la carpeta al aprobar: mover archivos ya subidos para ganar un
     * nombre más lindo es la clase de operación que rompe rutas guardadas.
     */
    protected function carpetaDeAdjuntos(): string
    {
        return 'no-conformidades/'.$this->segmentoSeguro($this->numero, 'borrador-'.$this->id);
    }

    public function estaFinalizada(): bool
    {
        return in_array($this->estado, self::ESTADOS_FINALES, true);
    }

    /** Si desde el estado actual se puede pasar a `$destino` por el flujo normal. */
    public function puedeTransicionarA(string $destino): bool
    {
        return in_array($destino, self::TRANSICIONES[$this->estado] ?? [], true);
    }

    /**
     * Si la investigación permite avanzar al plan de acción.
     *
     * §4.4 es explícito sobre qué traba el avance: *"No se podrá avanzar al
     * Plan de acción mientras **la investigación y el análisis de causa** se
     * encuentren incompletos"*.
     *
     * ⚠️ Son esos dos y nada más, a propósito. La etapa pide completar bastante
     * más (contención, alcance, afectados, riesgo), pero el instructivo no los
     * pone como condición para avanzar, y convertir una recomendación en un
     * bloqueo haría que la pantalla frene a alguien por un campo que su caso no
     * necesita — "productos y lotes afectados" no aplica a una NC de proceso.
     */
    public function investigacionCompleta(): bool
    {
        return filled($this->investigacion) && filled($this->causa_raiz);
    }

    /**
     * Si el plan de acción permite avanzar a implementación.
     *
     * §4.5: "una vez definido el plan, Gestión de Calidad deberá revisar que
     * las acciones sean adecuadas antes de iniciar su implementación". Un plan
     * sin ninguna acción no es un plan.
     */
    public function tienePlanDeAccion(): bool
    {
        return $this->acciones()->exists();
    }

    /**
     * Si todas las acciones están resueltas y se puede verificar la eficacia.
     *
     * §4.6: *"La NC no podrá avanzar a Verificación de eficacia mientras
     * existan acciones obligatorias pendientes"*. Una acción **cancelada con
     * justificación** no es una pendiente: es una decisión tomada. Las vencidas
     * sí traban — que se haya pasado la fecha no significa que esté hecha.
     */
    public function accionesResueltas(): bool
    {
        return ! $this->acciones()->pendientes()->exists();
    }

    /**
     * Las **cinco** condiciones que traban el cierre, evaluadas una por una
     * para poder decirle al usuario cuál le falta en vez de un "no se puede
     * cerrar" mudo.
     *
     * ⚠️ §4.8 enumera siete. Dos dejaron de trabar el 28/9/2026 y pasaron a
     * `advertenciasDeCierre()`:
     *
     * - **Las evidencias adjuntas.** El cliente confirmó que no es obligatorio
     *   ("NO, no es obligatorio"): hay desvíos que se resuelven sin nada que
     *   adjuntar, y exigirlo obligaba a subir un archivo de relleno.
     * - **Las observaciones vinculadas cerradas.** Desde que una observación se
     *   deriva a un desvío y **se cierra a mano, por separado**, esta condición
     *   producía un bloqueo mutuo: el desvío esperaba a la observación y la
     *   observación esperaba el resultado del desvío. Ninguno de los dos cerraba
     *   nunca.
     *
     * No desaparecieron de la pantalla: se avisan sin trabar.
     *
     * @return array<string, bool>
     */
    public function condicionesDeCierre(): array
    {
        return [
            // Las dos primeras de §4.8 son las mismas que traban el avance al
            // plan de acción, así que se reusa la misma definición.
            'investigacion' => filled($this->investigacion),
            'causa' => filled($this->causa_raiz),
            'acciones' => $this->tienePlanDeAccion() && $this->accionesResueltas(),
            'verificada' => filled($this->resultado_eficacia),
            // ⚠️ No es "la verificación dio eficaz" a secas. Un resultado
            // ineficaz también se cierra —el formulario tiene fecha y
            // responsable de cierre para los dos casos— pero recién una vez que
            // se abrió el desvío que la reemplaza. Sin esta segunda mitad, una
            // NC ineficaz no podría cerrarse nunca y quedaría colgada para
            // siempre en verificación.
            'eficaz' => $this->resultado_eficacia === 'eficaz'
                || ($this->resultado_eficacia === 'ineficaz' && $this->reemplazadaPor()->exists()),
        ];
    }

    /**
     * Lo que conviene mirar antes de cerrar, pero **no** traba.
     *
     * Son las dos condiciones que §4.8 pedía y que dejaron de ser obligatorias
     * — ver `condicionesDeCierre()`. Borrarlas del todo perdía información útil:
     * cerrar sin ninguna evidencia, o dejando observaciones abiertas, sigue
     * siendo algo que vale la pena saber en el momento de cerrar. La diferencia
     * es que ahora se avisa y se sigue.
     *
     * Devuelve solo las que aplican, ya redactadas: la pantalla y el controller
     * las muestran tal cual y no tienen que saber de dónde salen.
     *
     * @return list<string>
     */
    public function advertenciasDeCierre(): array
    {
        $avisos = [];

        if (! $this->attachments()->exists()) {
            $avisos[] = 'No hay ninguna evidencia adjunta.';
        }

        $abiertas = $this->observaciones()
            ->whereIn('estado', Observacion::ESTADOS_ABIERTOS)
            ->count();

        if ($abiertas > 0) {
            $avisos[] = $abiertas === 1
                ? 'Queda 1 observación vinculada sin cerrar.'
                : "Quedan {$abiertas} observaciones vinculadas sin cerrar.";
        }

        return $avisos;
    }

    public function puedeCerrarse(): bool
    {
        return ! in_array(false, $this->condicionesDeCierre(), true);
    }

    public function etiquetaEstado(): string
    {
        return self::ESTADOS[$this->estado] ?? $this->estado;
    }

    // ── Relaciones ──────────────────────────────────────────────────────────

    public function attachments(): HasMany
    {
        return $this->hasMany(NonConformityAttachment::class, 'non_conformity_id');
    }

    public function historial(): HasMany
    {
        return $this->hasMany(NonConformityHistory::class, 'non_conformity_id');
    }

    /** El plan de acción (§4.5). Es lo que el instructivo llama CAPA. */
    public function acciones(): HasMany
    {
        return $this->hasMany(NonConformityAction::class, 'non_conformity_id');
    }

    /**
     * Las acciones inmediatas de contención (sección 3 del formulario): lo que
     * se hizo enseguida para frenar el problema, antes de conocer la causa.
     */
    public function contenciones(): HasMany
    {
        return $this->hasMany(NonConformityContainment::class, 'non_conformity_id');
    }

    /**
     * La NC que ésta reemplaza, cuando nació de una verificación ineficaz.
     *
     * ⚠️ Es el lado que **tiene** la columna. La pregunta que hace el formulario
     * ("Nuevo desvío N°") es la inversa, `reemplazadaPor()`.
     */
    public function reemplazaA(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reemplaza_a_id');
    }

    /**
     * El desvío nuevo que se abrió porque las acciones de ésta no fueron
     * eficaces — la celda "Nuevo desvío N°" de la sección 7.
     *
     * `hasOne` y no `hasMany` a propósito: una NC se reemplaza una sola vez. Si
     * la nueva tampoco resulta eficaz, la que se reemplaza es la nueva, y la
     * cadena se lee saltando de una a la siguiente.
     */
    public function reemplazadaPor(): HasOne
    {
        return $this->hasOne(self::class, 'reemplaza_a_id');
    }

    /**
     * Observaciones que originaron esta NC (§5). Muchos-a-muchos: una NC puede
     * nacer de varias observaciones.
     */
    public function observaciones(): BelongsToMany
    {
        return $this->belongsToMany(Observacion::class, 'non_conformity_observation', 'non_conformity_id', 'observation_id')
            ->withTimestamps();
    }

    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    /**
     * Quién gestiona el caso de punta a punta.
     *
     * ⚠️ No es lo mismo que `creador`: cualquiera puede cargar un desvío, y
     * quien lo trabaja se designa al aprobarlo (o después). Es lo que permite
     * que Garantía de Calidad haga seguimiento y control sin ser responsable de
     * todas las NC — ver `NoConformidadPolicy::gestionar()`.
     */
    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    /**
     * ⚠️ Los nombres llevan sufijo `Usuario` a propósito: `aprobadaPor` se
     * serializaría como `aprobada_por`, que es el nombre de la columna `int`, y
     * el JSON de Inertia quedaría con el objeto o con el id según el orden de
     * serialización. Mismo criterio que `Observacion::cerradaPorUsuario()`.
     */
    public function aprobadaPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobada_por');
    }

    public function cerradaPorUsuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrada_por');
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    public function scopeAbiertas(Builder $query): Builder
    {
        return $query->whereIn('estado', self::ESTADOS_ABIERTOS);
    }

    /**
     * Buscador del listado: número, motivo, descripción, o razón social del
     * cliente o proveedor involucrado.
     *
     * El número se busca sin distinguir mayúsculas y tolerando que el usuario
     * escriba o no el prefijo (`nc-0001`, `0001`).
     *
     * ⚠️ El **motivo entró acá cuando dejó de ser una lista cerrada**: siendo
     * texto libre, el buscador es la única forma de encontrar una NC por su
     * motivo — el listado no tiene (ni puede tener) un filtro desplegable.
     */
    public function scopeBuscar(Builder $query, string $termino): Builder
    {
        $termino = trim($termino);

        if ($termino === '') {
            return $query;
        }

        $like = '%'.$termino.'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('numero', 'like', $like)
                ->orWhere('motivo', 'like', $like)
                ->orWhere('descripcion', 'like', $like)
                ->orWhereHas('cliente', fn (Builder $c) => $c->where('razon_social', 'like', $like))
                ->orWhereHas('proveedor', fn (Builder $p) => $p->where('razon_social', 'like', $like));
        });
    }
}
