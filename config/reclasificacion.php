<?php

/*
|--------------------------------------------------------------------------
| Reclasificación de casos ya cargados (`observaciones:reclasificar`)
|--------------------------------------------------------------------------
|
| Sale de la tabla del prototipo del cliente ("Dashboard SGO TUBLOOD", 6/11/2026,
| sobre las 70 observaciones de septiembre a noviembre): al crearse los fallos
| externos (E.1, E.2, E.3) y retirarse 3.5 y 6.2, los casos que ya estaban
| cargados tienen que pasar al tipo que les corresponde.
|
| ⚠️ Es una escritura masiva sobre producción. El comando **simula por defecto**
| y solo escribe con `--aplicar`; antes de aplicar hay que revisar la lista con
| quien conoce los casos. Cada cambio deja su entrada en la bitácora (acción
| `tipo`, con los datos que se reemplazan) y una nota con el motivo.
|
| Los números van como figuran en `observations.numero` (`NNNN-AA`).
*/

return [

    /*
    | Casos puntuales: número => tipo destino, y lo que ya se sabe del caso.
    |
    | `datos` son "Datos específicos" del tipo destino. NO se validan con las
    | reglas del alta: un caso histórico no tiene la guía ni el remito que E.1
    | pide hoy, y exigirlos lo dejaría sin reclasificar. Lo que se sabe va acá; lo
    | que no, queda vacío para completarlo desde la ficha.
    */
    'casos' => [
        // 3.5 y la 0011 (que figura como 1.2 pero es del transporte) → E.1.
        '0011-26' => ['tipo' => 'falla_logistica_tercerizada', 'datos' => ['transporte' => 'Sneider'], 'motivo' => 'Figura como 1.2: es una falla del transporte (Sneider).'],
        '0020-26' => ['tipo' => 'falla_logistica_tercerizada', 'datos' => ['transporte' => 'Demonte'], 'motivo' => 'Hoy 3.5: falla del transporte (Demonte).'],
        '0035-26' => ['tipo' => 'falla_logistica_tercerizada', 'datos' => ['transporte' => 'Cruz del Sur'], 'motivo' => 'Hoy 3.5: falla del transporte (Cruz del Sur).'],

        // 6.2 y lo de Producción parada por el proveedor → E.2.
        '0015-26' => ['tipo' => 'falla_proveedor_compras', 'datos' => ['proveedor' => 'AD Flex (etiquetas)', 'motivo' => 'Producto no conforme'], 'motivo' => 'Hoy 6.2: proveedor de etiquetas.'],
        '0053-26' => ['tipo' => 'falla_proveedor_compras', 'datos' => ['motivo' => 'Demora en entrega'], 'motivo' => 'Hoy 6.2: proveedor de equipamiento.'],
        '0073-26' => ['tipo' => 'falla_proveedor_compras', 'datos' => ['proveedor' => 'AD Flex (etiquetas)'], 'motivo' => 'Hoy 10.4: producción parada por falta de etiquetas del proveedor.'],

        // Casos que el selector no dejaba pasar al sector que de verdad falló.
        '0045-26' => ['tipo' => 'falla_proceso_dep', 'datos' => [], 'motivo' => 'Figuraba como 1.2; el selector no dejaba elegir 4.4.'],
        '0058-26' => ['tipo' => 'pedido_mal_armado', 'datos' => [], 'motivo' => 'Figuraba como 3.7; el cliente retiró en TUBLOOD, es 4.1.'],
    ],

    /*
    | 1.1 "Falla de producto" según de quién es el producto.
    |
    | Lo de reventa pasa a E.3 (el responsable técnico es el fabricante); lo propio
    | se queda en 1.1. Se decide por el código de los productos reclamados.
    |
    | ⚠️ NO existe un dato de "producto propio / reventa" en el sistema: es una
    | regla por prefijo del código que el cliente todavía tiene que confirmar. Lo
    | que no encaja en ninguna (o mezcla las dos) NO se toca y se avisa como "a
    | confirmar": mandar un caso al tipo equivocado es peor que dejarlo donde está.
    */
    'producto_1_1' => [
        'reventa' => '/^RE-/i',
        'propio' => '/^(KS|VC)/i',
        'destino_reventa' => 'falla_calidad_distribucion',
    ],

];
