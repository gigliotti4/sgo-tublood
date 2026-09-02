<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * De dónde salió el `proveedor_id` de cada artículo.
 *
 * Hace falta porque hay tres fuentes automáticas escribiendo la misma columna
 * y hasta ahora ninguna sabía de la otra: la única regla era "no pisar nada
 * ya cargado". Eso protegía las correcciones a mano, pero también dejaba
 * congelada una adivinanza del Excel aunque después llegara el dato bueno del
 * ERP. Con el origen guardado, la precedencia se puede decidir de verdad —
 * ver `App\Services\VinculacionProveedores`.
 *
 * ⚠️ **No se llama `origen` a secas.** El export de artículos ya tiene una
 * columna **Origen** que significa otra cosa: si el artículo vino de RP o lo
 * creó el Excel de Calidad con "crear faltantes" (ver `ArticuloExportService`).
 * Son dos preguntas distintas sobre la misma fila y confundirlas sería fácil.
 *
 * ⚠️ **Los artículos que ya tienen proveedor se marcan `excel`**, que es de
 * donde vino la enorme mayoría (el import los vincula por razón social
 * normalizada). Es una decisión consciente: deja que RP los corrija cuando
 * cargue `codigo_proveedor`. No hay auditoría de artículos, así que si alguna
 * de esas asignaciones se hizo a mano en el panel, queda expuesta a que la
 * pisen — se aceptó el riesgo a cambio de que el dato del ERP pueda corregir
 * lo que el Excel adivinó mal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->string('proveedor_origen', 10)->nullable()->after('proveedor_id')->index();
        });

        DB::table('articulos')->whereNotNull('proveedor_id')->update(['proveedor_origen' => 'excel']);
    }

    public function down(): void
    {
        Schema::table('articulos', function (Blueprint $table) {
            $table->dropColumn('proveedor_origen');
        });
    }
};
