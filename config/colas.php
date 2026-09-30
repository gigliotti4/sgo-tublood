<?php

use App\Jobs\SyncClientesJob;

return [

    /*
    |--------------------------------------------------------------------------
    | Aviso por tareas de fondo que fallan
    |--------------------------------------------------------------------------
    |
    | Lo lee `colas:revisar-fallos`, que corre una vez por día y avisa a los
    | super-admin cuando una clase de job falla más de lo normal.
    |
    | ⚠️ Vive en config y no hardcodeado porque **el umbral es lo que decide si
    | el aviso sirve o es ruido**, y eso solo se afina con el sistema andando.
    | Un umbral bajo llena la campana de algo que ya se sabe y en una semana
    | nadie lo mira; uno alto tapa justo lo que hay que ver. Poder corregirlo
    | sin un deploy es la diferencia entre ajustarlo y abandonarlo.
    |
    */

    'umbral_fallos' => [

        /*
         * Para cualquier job que no tenga umbral propio.
         *
         * Va bajo a propósito: un job que normalmente **no falla** empezando a
         * fallar es exactamente la señal que se busca, aunque sean pocas veces.
         */
        'default' => 3,

        /*
         * Los que fallan seguido por causas de afuera y ya se sabe.
         *
         * ⚠️ `SyncClientesJob` corre cada 5 minutos —288 veces por día— contra
         * el ERP de RP Sistemas, que devuelve 502 o no responde de a ratos.
         * Medido entre el 24/8 y el 29/9/2026: ~9 fallos por día, el 3% de las
         * corridas, y **el sync se recupera solo en el intento siguiente**. Por
         * eso el umbral es alto: acá solo interesa saber si RP se cayó de
         * verdad, no que tuvo un hipo.
         */
        'por_clase' => [
            SyncClientesJob::class => 30,
        ],
    ],

    /*
     * Cuántas horas hacia atrás mira el chequeo. Tiene que cubrir al menos lo
     * que va de una corrida a la siguiente, o hay fallos que no se miran nunca.
     */
    'ventana_horas' => 24,

];
