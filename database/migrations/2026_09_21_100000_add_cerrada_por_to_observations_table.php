<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quién cerró cada observación, para el listado de `/bajas`, que ahora incluye
 * las cerradas además de las borradas y las canceladas.
 *
 * Columna propia y **no** una lectura de la bitácora, por el mismo motivo por
 * el que existe `cerrada_at`: `observation_history` guarda la **etiqueta
 * legible** del estado y no el slug, así que no es consultable de forma
 * confiable. Ya pasó una vez — hasta el 31/7/2026 existía el estado `resuelta`
 * y las entradas de esa época dicen 'Resuelta'.
 *
 * La escribe `ObservacionObserver::marcarCierre()`, en el mismo UPDATE que el
 * cambio de estado, y vuelve a null si el caso se reabre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            // Nullable: las cerradas antes de este campo, las que cierre un
            // proceso sin usuario detrás (un comando, un seeder) y las que se
            // reabran no tienen autor.
            $table->foreignId('cerrada_por')
                ->nullable()
                ->after('cerrada_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        $this->backfillDesdeLaBitacora();
    }

    public function down(): void
    {
        Schema::table('observations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cerrada_por');
        });
    }

    /**
     * Recupera el autor del cierre de los casos ya cerrados leyendo su bitácora.
     *
     * Misma técnica y misma justificación que el backfill de `cerrada_at`: es
     * la única oportunidad de rescatar el dato. Lo que **no** es confiable de
     * la bitácora es la etiqueta con la que hay que *encontrar* la fila (por
     * eso se buscan las dos, 'Cerrada' y 'Resuelta'); el `user_id` de esa fila
     * sí lo es, porque es una FK. Para un backfill de una sola vez alcanza.
     */
    private function backfillDesdeLaBitacora(): void
    {
        $cerradas = DB::table('observations')
            ->where('estado', 'cerrada')
            ->whereNotNull('cerrada_at')
            ->pluck('id');

        foreach ($cerradas as $observationId) {
            $entrada = DB::table('observation_history')
                ->where('observation_id', $observationId)
                ->where('accion', 'estado')
                ->whereNotNull('user_id')
                ->orderByDesc('created_at')
                ->get(['cambios', 'user_id'])
                ->first(function ($fila) {
                    $cambios = json_decode($fila->cambios ?? '[]', true);

                    return in_array($cambios['a'] ?? null, ['Cerrada', 'Resuelta'], true);
                });

            if ($entrada) {
                DB::table('observations')
                    ->where('id', $observationId)
                    ->update(['cerrada_por' => $entrada->user_id]);
            }
        }
    }
};
