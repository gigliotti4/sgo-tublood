<?php

/*
|--------------------------------------------------------------------------
| Alias de sectores del import de usuarios
|--------------------------------------------------------------------------
|
| El Excel de Tublood trae el "sector original" con nombres que no siempre
| matchean el slug del sector de gestión equivalente en `sectors`. Este mapa
| traduce el slug derivado del nombre del Excel al slug real del catálogo.
|
| Clave = Str::slug(nombre tal como viene en el Excel), valor = slug del
| sector en `sectors`. El catálogo de sectores es fijo: un nombre que no
| aparece acá ni matchea un slug existente deja al usuario sin sector.
|
*/

return [

    'alias_sectores' => [
        'calidad' => 'garantia_calidad',
        'ventas' => 'comercial',
        'asuntos-regulatorios-gestion-de-calidad' => 'asuntos_regulatorios',
        // El sector se llama "Compras" pero su slug quedó como `comex` de
        // cuando ese era su nombre; el Excel trae COMPRAS.
        'compras' => 'comex',
    ],

];
