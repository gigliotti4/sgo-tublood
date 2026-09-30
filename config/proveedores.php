<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Clasificación de proveedores del ERP (AGRU_1)
    |--------------------------------------------------------------------------
    |
    | Espeja `powerbi_proveedores_vista.AGRU_1`, que RP Sistemas mantiene para
    | separar a quién le compramos producto médico de quién nos factura un
    | servicio. Las etiquetas están **copiadas textuales** de la tabla
    | `AGRUP_PROVE` del ERP (`CODI_AGRU` / `DESCRIP_AGRU`, filas con
    | `NUM_AGRU = 1`), para que digan lo mismo que la pantalla de RP y nadie
    | tenga que traducir entre los dos sistemas.
    |
    | ⚠️ **No es `tipo_proveedor`.** Ése es nuestro: lo elige una persona en el
    | panel y decide qué documentación se le exige (`config/documentacion.php`).
    | Éste lo carga RP y no se edita desde acá — la sincronización lo pisa en
    | cada corrida. Son dos clasificaciones distintas de la misma empresa y por
    | eso en pantalla se llaman distinto ("Tipo" vs "Clasificación (ERP)").
    |
    | ⚠️ **Un código que no esté acá no rompe nada**: la sincronización guarda
    | lo que venga y el listado muestra el código crudo. Lo único que pierde es
    | la etiqueta y el lugar en el filtro, que se arregla agregando una línea
    | acá sin tocar código. Es a propósito: el catálogo es de RP, no nuestro, y
    | pueden sumar un código cualquier día.
    |
    | Medido el 30/9/2026 sobre los 1.878 proveedores de la vista: 90 en `01`,
    | 174 en `02`, 843 en `03` y 771 sin clasificar.
    |
    */

    'clasificaciones' => [
        '01' => 'PROV. APROBADOS DE PM',
        '02' => 'PROV. PROD. VENTA LIBRE',
        '03' => 'SERVICIOS / HONORARIOS',
    ],

];
