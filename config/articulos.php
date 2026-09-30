<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tipo de producto según ANMAT (ID_ARTI_TIPO)
    |--------------------------------------------------------------------------
    |
    | Espeja `ARTICULOS.ID_ARTI_TIPO` del ERP. Las etiquetas están copiadas
    | textuales de la tabla `WS_ANMAT_ARTICULOS_TIPOS` (`ID_ARTI_TIPO` /
    | `DESCRIP_TIPO`), para que digan lo mismo que la pantalla de RP.
    |
    | ⚠️ Un código que no esté acá no rompe nada: la sincronización guarda lo que
    | venga y la pantalla muestra el código crudo. El catálogo es de RP, no
    | nuestro, y pueden sumar uno cualquier día.
    |
    | Medido el 30/9/2026 sobre los 5.236 artículos del ERP: 580 en `PM`, 230 en
    | `PMV`, 10 en `ME` y 4.416 sin tipo. De nuestro catálogo de 748, 330 lo
    | tienen cargado.
    |
    */

    'tipos_anmat' => [
        'PM' => 'Producto Medico',
        'PMV' => 'Producto Medico VL',
        'ME' => 'Medicamento',
        'A' => 'Alimento',
    ],

];
