<?php

use App\Models\Observacion;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La lista de `tipos_caso` de config/incidencias.php se reemplazó por completo.
 * Los valores viejos ('Producto defectuoso', 'Riesgo sanitario', etc.) ya no
 * validan, así que las observaciones que los tengan no se pueden guardar hasta
 * reclasificarlas.
 *
 * Se limpian **solo las observaciones abiertas**: las cerradas y canceladas
 * conservan su valor histórico. En un sistema de gestión de calidad, borrar
 * cómo se clasificó un caso ya terminado es peor que tener ahí un valor fuera
 * de la lista actual — y nadie las edita, así que la validación no las toca.
 *
 * Ojo: una observación en estado `clasificada` queda con `tipo_caso` nulo, o
 * sea "clasificada pero sin tipo". Es incoherente a propósito: reabrirlas
 * automáticamente (volverlas a `pendiente_clasificacion`) sería peor, porque
 * pisaría el trabajo de clasificación que ya hizo alguien. Al editarlas hay que
 * elegir un tipo de la lista nueva.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('observations')
            ->whereIn('estado', Observacion::ESTADOS_ABIERTOS)
            ->whereNotNull('tipo_caso')
            ->whereNotIn('tipo_caso', config('incidencias.tipos_caso'))
            ->update(['tipo_caso' => null]);
    }

    /**
     * No hay vuelta atrás: el valor viejo no se guardó en ningún lado. Queda
     * registrado en la bitácora de cada caso si alguna vez se clasificó desde
     * el panel.
     */
    public function down(): void {}
};
