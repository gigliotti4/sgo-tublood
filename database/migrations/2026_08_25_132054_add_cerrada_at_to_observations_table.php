<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuándo se cerró cada observación, para el KPI de tiempo promedio de
 * resolución del Dashboard.
 *
 * No alcanzaba con lo que ya había: `updated_at` lo pisa cualquier edición
 * posterior (un comentario, un adjunto), y la bitácora —el único registro real
 * del cierre hasta ahora— no es consultable de forma confiable, porque guarda
 * la **etiqueta legible** del estado y no el slug. Ya pasó una vez: hasta el
 * 31/7/2026 existía el estado `resuelta` y la migración que lo unificó en
 * `cerrada` solo tocó `observations.estado`, así que las entradas de esa época
 * dicen 'Resuelta'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            // Indexado: el KPI filtra por este campo (cerradas de los últimos
            // 90 días).
            $table->timestamp('cerrada_at')->nullable()->after('vence_at')->index();
        });

        $this->backfillDesdeLaBitacora();
    }

    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->dropColumn('cerrada_at');
        });
    }

    /**
     * Recupera la fecha de cierre de los casos ya cerrados leyendo su bitácora.
     *
     * Es la única oportunidad de rescatar ese dato: una vez que el sistema se
     * empiece a usar de verdad, no va a haber forma de reconstruirlo.
     *
     * Busca las dos etiquetas ('Cerrada' y 'Resuelta') por el renombre de
     * julio. Las observaciones cerradas antes de que existiera la bitácora
     * (creada el 27/7/2026, con observaciones desde el 1/7) quedan en null y
     * simplemente no entran al promedio.
     */
    private function backfillDesdeLaBitacora(): void
    {
        $cerradas = DB::table('observations')->where('estado', 'cerrada')->pluck('id');

        foreach ($cerradas as $observationId) {
            $entrada = DB::table('observation_history')
                ->where('observation_id', $observationId)
                ->where('accion', 'estado')
                // `latest()` sobre created_at: si el caso se cerró, se reabrió
                // y se volvió a cerrar, vale el último cierre.
                ->orderByDesc('created_at')
                ->get(['cambios', 'created_at'])
                ->first(function ($fila) {
                    $cambios = json_decode($fila->cambios ?? '[]', true);

                    return in_array($cambios['a'] ?? null, ['Cerrada', 'Resuelta'], true);
                });

            if ($entrada) {
                DB::table('observations')
                    ->where('id', $observationId)
                    ->update(['cerrada_at' => $entrada->created_at]);
            }
        }
    }
};
