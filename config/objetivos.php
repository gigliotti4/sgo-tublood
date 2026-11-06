<?php

/*
|--------------------------------------------------------------------------
| Objetivos de gestión: cómo se lee la planilla "OBJETIVOS 2026"
|--------------------------------------------------------------------------
|
| `IndicadorImportService` lee la planilla del cliente y traduce tres cosas que
| no vienen en un formato que el SGO entienda. Las tres viven acá, editables,
| porque son decisiones del cliente y van a cambiar:
|
|  1. El sector de la planilla → el sector del SGO.
|  2. La celda "FUENTE / TIPO SGO" ("SGO · 5.1 + 5.3") → qué tipos cuenta.
|  3. Los calificadores entre paréntesis ("sin 0062", "demora", "calidad"), que
|     parten UN tipo en DOS indicadores.
|
| Todo lo que se deduzca de acá se muestra en la vista previa antes de aplicar, y
| se puede corregir después a mano en cada indicador.
*/

return [

    /*
    | Sector de la planilla → slug del sector del SGO. La clave va en mayúsculas y sin
    | acentos. La planilla tiene sectores que el SGO no (COMPRAS y COMEX separados,
    | Producción partida en Apósitos y Tubos): los dos van al sector que corresponde
    | en `sectors`, pero el indicador conserva el nombre que trae la planilla.
    */
    'sectores' => [
        'CALIDAD' => 'garantia_calidad',
        'COMPRAS' => 'comex',
        'COMEX' => 'comex',
        'DEPOSITO' => 'deposito',
        'FACTURACION' => 'facturacion',
        'LOGISTICA' => 'logistica',
        'COMERCIAL' => 'comercial',
        'REGULATORIO' => 'asuntos_regulatorios',
        'FINANZAS' => 'finanzas',
        'DIRECCION' => 'direccion_tecnica',
        'PRODUCCION' => 'produccion',
        'PRODUCCION TUBOS' => 'produccion',
        'PRODUCCION APOSITOS' => 'produccion',
    ],

    /*
    | Código de la planilla → códigos que se cuentan HOY.
    |
    | ⚠️ La planilla sigue la clasificación VIEJA (3.5, 6.2 y "1.1 = todos los reclamos
    | de producto"), pero desde el 6/11/2026 esos casos viven en los fallos externos:
    | 3.5 pasó a E.1, 6.2 a E.2 y la reventa de 1.1 a E.3. Sin esta tabla el indicador
    | "Reclamos de producto" contaría solo lo propio, y los de fletes y proveedores
    | quedarían en cero.
    |
    | Un código que no está acá se cuenta tal cual.
    */
    'equivalencias' => [
        '1.1' => ['1.1', 'E.3'],
        '3.5' => ['E.1'],
        '6.2' => ['E.2'],
    ],

    /*
    | Calificadores entre paréntesis de la celda "FUENTE / TIPO SGO".
    |
    | Parten un tipo en dos indicadores: "3.7 (sin 0062)" son las fallas de manipulación
    | y "3.7 (retiro sin OC)" los retiros sin orden de compra, que son lo mismo en el
    | sistema y se distinguen por el título. Criterios posibles:
    |   titulo_contiene / titulo_no_contiene : texto en el título (sin distinguir mayúsculas)
    |   motivo / motivo_no                   : el motivo de un fallo externo (E.2)
    */
    'calificadores' => [
        ['patron' => '/sin\s+0062/i', 'criterios' => ['titulo_no_contiene' => 'ORDEN DE COMPRA']],
        ['patron' => '/retiro\s+sin\s+OC/i', 'criterios' => ['titulo_contiene' => 'ORDEN DE COMPRA']],
        ['patron' => '/\(\s*demora\s*\)/i', 'criterios' => ['motivo' => ['Demora en entrega']]],
        ['patron' => '/\(\s*calidad\s*\)/i', 'criterios' => ['motivo_no' => ['Demora en entrega']]],
    ],

    /*
    | Textos del TIPO DE META de la planilla → valor interno.
    */
    'tipos_meta' => [
        'MAXIMO DE FALLOS' => 'maximo',
        'MINIMO A ALCANZAR' => 'minimo',
    ],

    'estados' => [
        'APROBADO' => 'aprobado',
        'PROPUESTA A APROBAR' => 'propuesta',
        'REVISAR' => 'revisar',
    ],

];
