<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Categorías de artículo (columna AGRU_1 de ARTICULOS)
    |--------------------------------------------------------------------------
    |
    | El ERP guarda el código; acá está el nombre que ve Compras. Un código que
    | no esté en esta lista se muestra tal cual (no se pierde el renglón), y los
    | artículos sin categoría caen en SIN_CAT.
    |
    | SERVICIOS no es una categoría del ERP: es la que se le asigna a los
    | artículos con SIN_STOCK = 'S'. Ver `servicios` más abajo.
    |
    */

    'categorias' => [
        'CARA' => 'CARESTAINER ABIERTO',
        'AGUJ' => 'CARESTAINER AGUJAS',
        'CAR' => 'CARESTAINER VACIO',
        'DIS' => 'DISTRIBUCIÓN',
        'FABAA' => 'FABRICACIÓN APÓSITOS',
        'FAB' => 'FABRICACIÓN TUBOS',
        'HIS' => 'HISOPOS RONGYE',
        'IMP' => 'IMPORTACIÓN',
        'HEP' => 'JERINGA CON HEPARINA',
        'MAP' => 'MATERIA PRIMA',
        'NUT' => 'NUTRICION',
        'SERV' => 'SERVICIOS PRESTADOS',
        'ALLT' => 'TEST RÁPIDOS',
        'WEI' => 'WEIGAO',
        'EQ' => 'ZZZ EQUIPAMIENTO',
        'JER' => 'ZZZ JERINGA AYSET',
        'RE' => 'ZZZ NO SE USA',
        'REAC' => 'ZZZ REACTIVOS',
        '9' => 'ZZZ TIPO DE ARTICULO',
        'BOL' => 'ZZZBOLSAS DE SANGRE',
        'VAR' => 'ZZZVARIOS',
        'SERVICIOS' => 'SERVICIOS (no mueven stock)',
        'SIN_CAT' => 'SIN CATEGORÍA',
    ],

    /*
    |--------------------------------------------------------------------------
    | GTIN comodín
    |--------------------------------------------------------------------------
    |
    | ⚠️ `ARTICULOS.GTIN` NO es un código de barras: es una clasificación de
    | texto cargada a mano ("AGUJA 25/6 (23GX1)", "GUANTES DE LATEX M") que se
    | usa para unificar el mismo producto de distintas marcas en un renglón.
    |
    | Como se carga a mano, está lleno de comodines: hoy "NO APLICA" (559),
    | "N/A" (27), "0" (27), más typos ("NO APLCIA", "NO APLICO"). Se descartan
    | POR PATRÓN y no por lista fija justamente porque van a aparecer typos
    | nuevos. Un artículo con GTIN comodín o vacío va en su propio renglón.
    |
    | El patrón se aplica al ARMAR el dataset, no al sincronizar: así corregirlo
    | es editar este archivo, y no esperar a la sync del día siguiente.
    |
    */

    'gtin_comodin' => '/^(no ?apl\w*|n\/?a|s\/?d|null|none|ninguno|sin ?gtin|0+|-+|\.+)$/i',

    /*
    |--------------------------------------------------------------------------
    | Reservado
    |--------------------------------------------------------------------------
    |
    | Qué líneas de pedido comprometen stock. CUARENTENA y PROGRAMADAS no.
    |
    | ⚠️ ESTE NÚMERO NO ESTÁ CONCILIADO CON EL ERP. Las líneas que el ERP cuenta
    | y las que no son idénticas en `reser`, depósito y `estado`, así que el
    | campo que decide la reserva no está en la vista. Por eso la regla vive acá
    | y no hardcodeada: se ajusta sin tocar código ni desplegar.
    |
    | Pista medida el 8/9/2026: de las 511 líneas que hoy cuentan como
    | reservadas, 160 en estado ADMINISTRACION explican 122.928 de las 170.990
    | unidades (72%). Si al conciliar contra la pantalla del ERP sobra ese
    | volumen, agregar 'ADMINISTRACION' a `estados_excluidos`.
    |
    */

    'reserva' => [
        'depositos' => ['Deposito unico'],
        'estados_excluidos' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tablero
    |--------------------------------------------------------------------------
    */

    // Botonera "Meses de stock objetivo".
    'meses_objetivo' => [1, 1.5, 2, 3],
    'meses_objetivo_default' => 2,

    // Cuántos meses de ventas se mandan al tablero. 25 son ~2 años, que es todo
    // lo que hay en `ventas` (la sync cubre desde 2024-08).
    'meses_historial' => 25,

    /*
    |--------------------------------------------------------------------------
    | Alerta mensual de productos que no cubren stock
    |--------------------------------------------------------------------------
    |
    | La manda `compras:alerta-cobertura` el día 1 de cada mes a las 08:00 (hora
    | argentina), por mail y con el detalle en Excel, a las direcciones de
    | `destinatarios`. Son los filtros que Compras marcó en el tablero (pedido
    | del 8/10/2026), con los mismos valores que las botoneras: ver
    | `FiltrosCompras` en lib/compras.ts.
    |
    | ⚠️ `destinatarios` es una lista fija y no "quien tenga compras.view": ese
    | permiso lo tenían 22 usuarios (viene con roles generales) y el mail le
    | habría llegado a media empresa. Sumar o sacar a alguien es editar esta
    | lista y desplegar.
    |
    | ⚠️ `ultimos_meses` cuenta meses CERRADOS. El día 1 el mes recién empezado
    | trae ventas casi en 0 y, si entrara en el período, bajaría el promedio y
    | escondería productos que sí hay que comprar. El tablero, en cambio, sí
    | incluye el mes en curso (y lo avisa arriba).
    |
    */

    'alerta' => [
        'meses_objetivo' => 1,
        'ultimos_meses' => 3,
        // Códigos de AGRU_1 (ver `categorias`). DIS = DISTRIBUCIÓN.
        'categorias' => ['DIS'],
        'activo' => 'si',
        'stock' => 'all',
        'cubre' => 'no',
        'pareto' => 'all',
        // Vacío = todos los depósitos (el total del ERP).
        'depositos' => [],
        // Un renglón por artículo, igual que el tablero por defecto: se compra
        // por artículo y cada uno tiene su proveedor.
        'agrupar' => false,
        // Cuántos productos se listan en el cuerpo del mail; el resto va en el Excel.
        'top' => 10,
        // A quién le llega. Pedido de Compras del 9/10/2026.
        'destinatarios' => [
            'compras@tublood.com',
            'eduran@tublood.com',
        ],
    ],

];
