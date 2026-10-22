<?php

/*
|--------------------------------------------------------------------------
| Urgencias en facturas proforma
|--------------------------------------------------------------------------
|
| Qué cuenta como "urgente" en la observación general de una proforma
| (`powerbi_pedidos_vista.obser_cabe`). Lo aplica `App\Support\Urgencia` al
| armar la pantalla, no al sincronizar: corregir la regla es editar acá, sin
| esperar la sync del día siguiente (mismo criterio que `compras.gtin_comodin`).
|
| La regla es un port exacto de la que usaba el tablero suelto que leía el
| Excel (dashboard_urgencias_proformas_1.html), así los números no cambian al
| pasar al SGO. El pedido del cliente es que detecte "URGENCIA", "URGENTE o
| similar teniendo en cuenta que pudo haberse tipeado mal".
|
*/

return [

    // Palabras contra las que se mide la distancia de edición.
    'palabras' => ['URGENTE', 'URGENCIA', 'URGENTES', 'URGENCIAS', 'URGENTISIMO'],

    // Comienzos que alcanzan solos: el tipeo apurado suele romper el final de
    // la palabra pero no el principio ("URJENTE", "UGRENTE", "URRGENTE").
    'prefijos' => ['URG', 'URJ', 'UGR', 'URRG'],

    // Distancia de Levenshtein máxima contra alguna de las palabras, para las
    // que no empiezan con un prefijo. Solo se mide en palabras de 5 letras o más
    // que empiezan con U o R: con menos, "RUTA" o "UNICA" quedarían cerca.
    'distancia_maxima' => 2,
    'largo_minimo' => 5,

    // El tipo de comprobante de la factura proforma en el ERP.
    'comprobante' => 'DDD',
];
