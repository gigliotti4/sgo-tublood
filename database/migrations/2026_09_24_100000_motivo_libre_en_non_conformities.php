<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El motivo de una No Conformidad pasa a ser texto libre.
 *
 * Venía de §2 del instructivo, que lista ocho orígenes posibles, y se había
 * modelado como un `string(40)` con slug validado contra `NoConformidad::MOTIVOS`.
 * El formulario real (`Formulario_Informe_de_Desvio.xlsx`) lo escribe a mano
 * —"Mala implementación de código de barras"— y el cliente lo confirmó el
 * 24/9/2026: ninguna de las ocho categorías describe un caso concreto.
 *
 * ⚠️ El backfill traduce los slugs ya cargados a su etiqueta. Sin él, las filas
 * viejas mostrarían `auditoria` crudo en la ficha, porque ya no queda ningún
 * mapa que las pase a castellano.
 */
return new class extends Migration
{
    /** Las ocho de `NoConformidad::MOTIVOS`, congeladas acá: la constante se borra. */
    private const ETIQUETAS = [
        'observaciones' => 'Una o varias observaciones',
        'reclamo_cliente' => 'Un reclamo de cliente',
        'auditoria' => 'Un resultado de auditoría',
        'incumplimiento_procedimiento' => 'Un incumplimiento de un procedimiento',
        'falla_repetitiva' => 'Una falla repetitiva',
        'riesgo' => 'Un riesgo para el producto, cliente o sistema de gestión',
        'accion_ineficaz' => 'La falta de eficacia de una acción anterior',
        'deteccion_interna' => 'Una detección interna',
    ];

    public function up(): void
    {
        Schema::table('non_conformities', function (Blueprint $table) {
            $table->text('motivo')->change();
        });

        foreach (self::ETIQUETAS as $slug => $etiqueta) {
            DB::table('non_conformities')->where('motivo', $slug)->update(['motivo' => $etiqueta]);
        }
    }

    /**
     * Vuelve a los slugs lo que se pueda reconocer, y deja en `deteccion_interna`
     * lo que no: la columna vuelve a ser un `string(40)` validado, así que un
     * texto libre de 300 caracteres no entra de ninguna forma.
     */
    public function down(): void
    {
        foreach (self::ETIQUETAS as $slug => $etiqueta) {
            DB::table('non_conformities')->where('motivo', $etiqueta)->update(['motivo' => $slug]);
        }

        DB::table('non_conformities')
            ->whereNotIn('motivo', array_keys(self::ETIQUETAS))
            ->update(['motivo' => 'deteccion_interna']);

        Schema::table('non_conformities', function (Blueprint $table) {
            $table->string('motivo', 40)->change();
        });
    }
};
