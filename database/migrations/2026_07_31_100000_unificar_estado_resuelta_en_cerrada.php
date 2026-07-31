<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `resuelta` y `cerrada` decían lo mismo con dos palabras distintas, y la
 * diferencia no era neutra: solo `cerrada` está en `config('incidencias.estados_finales')`,
 * así que un caso marcado como resuelto seguía con el reloj de alertas corriendo
 * y sin el aviso final al gerente. Se unifican en `cerrada`.
 *
 * `cancelada` **no** entra acá: un caso anulado o duplicado no es trabajo
 * terminado, y el Dashboard lo excluye a propósito del gráfico por estado.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('observations')
            ->where('estado', 'resuelta')
            ->update(['estado' => 'cerrada']);
    }

    /**
     * Irreversible: después de la unificación no queda forma de saber cuáles de
     * las cerradas habían sido resueltas. El estado anterior de cada caso queda
     * en su bitácora, que es donde corresponde buscarlo.
     */
    public function down(): void {}
};
