<?php

/*
|--------------------------------------------------------------------------
| Taxonomía de incidencias internas
|--------------------------------------------------------------------------
|
| Fuente de verdad de los tipos de incidencia de la carga interna. Cada sector
| (clave = slug del registro en la tabla `sectors`) tiene sus tipos de
| incidencia, y cada tipo su lista de campos "Datos específicos" (que se guardan
| en la columna JSON `observations.datos_especificos`).
|
| Para agregar un tipo nuevo: agregar una entrada acá, sin tocar código.
|
| Campo (def): id, label, tipo (text|textarea|number|date|select|radio),
| required (bool), opciones (para select/radio).
|
| Un tipo puede llevar `requiere_cliente` (bool, hermano de `campos`) cuando
| necesita el N° de cliente del bloque base "Cliente" del formulario (no un
| campo propio en "Datos específicos": ese bloque ya resuelve el vínculo con
| la tabla `clientes`, duplicarlo ahí no lo hace).
|
*/

return [

    'prioridades' => [
        'critica' => 'Crítica',
        'alta' => 'Alta',
        'media' => 'Media',
        'baja' => 'Baja',
    ],

    // Estados que dan por terminada una observación: cortan el reloj de alertas
    // y disparan el aviso final al gerente del responsable.
    'estados_finales' => ['cerrada'],

    // Quién atiende cada tipo de reclamo que entra por el portal público.
    //
    // Define a quién le llega el mail del alta y a quién le aparece el reclamo
    // en la campana ("Sin clasificar") y en el modal de reclamos nuevos. Los
    // dos tipos externos pertenecen al sector Garantía de Calidad, así que el
    // sector solo no alcanza para repartirlos: hace falta este mapeo por rol.
    //
    // Agregar un tipo externo nuevo es sumar una línea acá, sin tocar código.
    'roles_por_tipo' => [
        'falla_producto' => 'calidad_producto',
        'disconformidad_servicio' => 'calidad_servicio',
    ],

    // De dónde salió el caso. Se guardan como texto legible (la columna
    // `observations.tipo_caso` es un string libre; la lista se valida acá).
    'tipos_caso' => [
        'Auditoría Externa',
        'Auditoría Interna',
        'Condiciones ambientales (T°, Humedad, Limp y Desinf)',
        'Desarrollo habitual de actividades',
        'Desvío en Sistemas Informáticos',
        'Devolución',
        'Encuesta de Satisfacción',
        'Equipos Internos',
        'Evaluación de Eficacia de la Capacitación',
        'Evaluaciones de Desempeño',
        'Inspecciones',
        'Monitoreo de Servicios',
        'Queja/Reclamo de clientes o partes interesadas',
        'Verificación de cumplimiento legal',
        'Verificación de productos comprados (Devolución a proveedores)',
    ],

    // Prioridad sugerida por tipo de caso (el usuario puede cambiarla).
    //
    // Vacío a propósito: el mapeo anterior era para los tipos de caso viejos.
    // Sugerir mal una prioridad en un sistema de dispositivos médicos es peor
    // que no sugerir nada, así que queda pendiente de definir con Calidad.
    // Mientras esté vacío, el campo Prioridad simplemente no se autocompleta.
    'prioridad_sugerida' => [],

    // Clave = slug del sector en la tabla `sectors`.
    'sectores' => [

        'facturacion' => [
            'error_facturacion' => [
                'codigo' => '2.1',
                'label' => 'Error de facturación',
                'campos' => [
                    ['id' => 'tipo_comprobante', 'label' => 'Tipo de comprobante', 'tipo' => 'radio', 'opciones' => ['Factura', 'Remito'], 'required' => true],
                    ['id' => 'numero_comprobante', 'label' => 'Número de factura/remito', 'tipo' => 'text', 'required' => true],
                    ['id' => 'lote_vencimiento', 'label' => 'Lote / Vencimiento', 'tipo' => 'text'],
                    ['id' => 'obs_no_contemplada', 'label' => 'Observación no contemplada', 'tipo' => 'textarea'],
                ],
            ],
            'demora_facturacion' => [
                'codigo' => '2.2',
                'label' => 'Demora de facturación / NC',
                'requiere_cliente' => true,
                'campos' => [
                    ['id' => 'numero_remito', 'label' => 'Número de remito', 'tipo' => 'text'],
                ],
            ],
        ],

        'logistica' => [
            'demora_entrega' => [
                'codigo' => '3.1',
                'label' => 'Demora en entrega',
                'campos' => [
                    ['id' => 'numero_hoja_ruta', 'label' => 'N° hoja de ruta', 'tipo' => 'text'],
                ],
            ],
            'siniestro_chofer' => [
                'codigo' => '3.2',
                'label' => 'Siniestro de chofer',
                'campos' => [
                    ['id' => 'nombre_chofer', 'label' => 'Nombre del chofer', 'tipo' => 'text'],
                    ['id' => 'vehiculo', 'label' => 'Vehículo / patente', 'tipo' => 'text'],
                    ['id' => 'ubicacion', 'label' => 'Ubicación', 'tipo' => 'text'],
                    ['id' => 'tipo_siniestro', 'label' => 'Tipo', 'tipo' => 'select', 'opciones' => ['Choque', 'Vuelco', 'Robo', 'Otro']],
                ],
            ],
            'falla_entrega' => [
                'codigo' => '3.3',
                'label' => 'Falla en la entrega',
                'campos' => [
                    ['id' => 'numero_remito', 'label' => 'Número de remito', 'tipo' => 'text', 'required' => true],
                ],
            ],
            'falla_producto_logistica' => [
                'codigo' => '3.4',
                'label' => 'Falla de producto por logística',
                'campos' => [
                    ['id' => 'numero_remito', 'label' => 'Número de remito', 'tipo' => 'text', 'required' => true],
                ],
            ],
            'falla_proveedor_externo' => [
                'codigo' => '3.5',
                'label' => 'Falla de proveedor externo',
                'campos' => [
                    ['id' => 'nombre_proveedor_externo', 'label' => 'Nombre del proveedor', 'tipo' => 'text', 'required' => true],
                ],
            ],
            'falla_administrativa_log' => [
                'codigo' => '3.6',
                'label' => 'Falla administrativa logística',
                'campos' => [
                    ['id' => 'detalle_administrativo', 'label' => 'Detalle', 'tipo' => 'textarea'],
                ],
            ],
        ],

        'deposito' => [
            'pedido_mal_armado' => [
                'codigo' => '4.1',
                'label' => 'Pedido mal armado',
                'requiere_cliente' => true,
                'campos' => [
                    ['id' => 'numero_remito', 'label' => 'Número de remito', 'tipo' => 'text', 'required' => true],
                ],
            ],
            'falla_recepcion' => [
                'codigo' => '4.2',
                'label' => 'Falla en recepción',
                'requiere_cliente' => true,
                'campos' => [
                    ['id' => 'codigo_producto', 'label' => 'Código de producto', 'tipo' => 'text', 'required' => true],
                    ['id' => 'descripcion', 'label' => 'Descripción', 'tipo' => 'text', 'required' => true],
                    ['id' => 'cantidades', 'label' => 'Cantidades', 'tipo' => 'number', 'required' => true],
                    ['id' => 'numero_proceso', 'label' => 'N° de proceso', 'tipo' => 'text', 'required' => true],
                ],
            ],
            'falla_wms' => [
                'codigo' => '4.3',
                'label' => 'Falla WMS',
                'campos' => [
                    ['id' => 'numero_remito', 'label' => 'Número de remito', 'tipo' => 'text', 'required' => true],
                    ['id' => 'descripcion_wms', 'label' => 'Descripción del problema', 'tipo' => 'textarea', 'required' => true],
                ],
            ],
            'falla_proceso_dep' => [
                'codigo' => '4.4',
                'label' => 'Falla de proceso',
                'campos' => [
                    ['id' => 'detalle_proceso', 'label' => 'Detalle', 'tipo' => 'textarea'],
                ],
            ],
        ],

        'comercial' => [
            'pedido_mal_cargado' => [
                'codigo' => '5.1',
                'label' => 'Pedido cargado incorrectamente',
                'campos' => [
                    ['id' => 'tipo_documento', 'label' => 'Tipo de documento', 'tipo' => 'select', 'opciones' => ['Presupuesto', 'Factura', 'Remito'], 'required' => true],
                    ['id' => 'numero_documento', 'label' => 'Número de documento', 'tipo' => 'text', 'required' => true],
                    ['id' => 'codigo_item', 'label' => 'Código de ítem', 'tipo' => 'text', 'required' => true],
                    ['id' => 'cantidades', 'label' => 'Cantidades', 'tipo' => 'number', 'required' => true],
                ],
            ],
            'demora_respuesta' => [
                'codigo' => '5.2',
                'label' => 'Demora de respuesta',
                'campos' => [
                    ['id' => 'observacion_libre', 'label' => 'Observación', 'tipo' => 'textarea'],
                ],
            ],
            'incumplimiento_proceso' => [
                'codigo' => '5.3',
                'label' => 'Incumplimiento / falla proceso',
                'campos' => [
                    ['id' => 'descripcion_libre', 'label' => 'Descripción', 'tipo' => 'textarea'],
                ],
            ],
            'error_pedido_cliente' => [
                'codigo' => '5.4',
                'label' => 'Error en pedido',
                'requiere_cliente' => true,
                'campos' => [
                    ['id' => 'codigo_producto', 'label' => 'Código de producto', 'tipo' => 'text', 'required' => true],
                ],
            ],
        ],

        'comex' => [
            'falla_compra' => [
                'codigo' => '6.1',
                'label' => 'Falla de compra',
                'campos' => [
                    ['id' => 'producto', 'label' => 'Producto', 'tipo' => 'text', 'required' => true],
                    ['id' => 'cantidades', 'label' => 'Cantidades', 'tipo' => 'number', 'required' => true],
                    ['id' => 'codigo_producto', 'label' => 'Código de producto', 'tipo' => 'text', 'required' => true],
                    ['id' => 'numero_oc', 'label' => 'Número de orden de compra', 'tipo' => 'text', 'required' => true],
                    ['id' => 'descripcion_compra', 'label' => 'Descripción', 'tipo' => 'textarea', 'required' => true],
                ],
            ],
            'falla_proveedor' => [
                'codigo' => '6.2',
                'label' => 'Falla de proveedor',
                'campos' => [
                    ['id' => 'numero_proveedor', 'label' => 'Número de proveedor', 'tipo' => 'text', 'required' => true],
                    ['id' => 'descripcion_proveedor', 'label' => 'Descripción', 'tipo' => 'textarea', 'required' => true],
                ],
            ],
            'falla_administrativa_comex' => [
                'codigo' => '6.3',
                'label' => 'Falla administrativa',
                'campos' => [
                    ['id' => 'descripcion_admin', 'label' => 'Descripción', 'tipo' => 'textarea'],
                ],
            ],
        ],

        'asuntos_regulatorios' => [
            'revision_regulatoria' => [
                'codigo' => '7.1',
                'label' => 'Revisión regulatoria',
                'campos' => [
                    ['id' => 'normativa', 'label' => 'Normativa aplicable', 'tipo' => 'text'],
                    ['id' => 'detalle', 'label' => 'Detalle', 'tipo' => 'textarea'],
                ],
            ],
            'falla_documental' => [
                'codigo' => '7.2',
                'label' => 'Falla documental',
                'campos' => [
                    ['id' => 'documento', 'label' => 'Documento', 'tipo' => 'text'],
                    ['id' => 'detalle', 'label' => 'Detalle', 'tipo' => 'textarea'],
                ],
            ],
        ],

        'garantia_calidad' => [
            // Tipos "especiales": son los del canal externo (lo que carga el cliente
            // por el portal público), pero se pueden cargar a mano acá cuando el
            // reclamo llega por teléfono o mail. Declaran acá su código y su nombre,
            // igual que el resto, pero no tienen `campos`: sus datos son productos
            // repetibles, que valida Admin\ObservacionController::storeInternaEspecial().
            'falla_producto' => [
                'codigo' => '1.1',
                'label' => 'Falla de producto',
                'especial' => true,
            ],
            'disconformidad_servicio' => [
                'codigo' => '1.2',
                'label' => 'Disconformidad de servicio',
                'especial' => true,
            ],
            'evaluacion_tecnica' => [
                'codigo' => '8.1',
                'label' => 'Evaluación técnica de caso',
                'campos' => [
                    ['id' => 'analisis', 'label' => 'Análisis técnico', 'tipo' => 'textarea', 'required' => true],
                    ['id' => 'conclusion', 'label' => 'Conclusión', 'tipo' => 'textarea'],
                ],
            ],
            'capa_seguimiento' => [
                'codigo' => '8.2',
                'label' => 'Seguimiento CAPA',
                'campos' => [
                    ['id' => 'numero_capa', 'label' => 'Número CAPA', 'tipo' => 'text', 'required' => true],
                    ['id' => 'estado_capa', 'label' => 'Estado actual', 'tipo' => 'select', 'opciones' => ['Abierto', 'En proceso', 'Implementado', 'Verificado', 'Cerrado']],
                ],
            ],
        ],

        'direccion_tecnica' => [
            'evaluacion_riesgo' => [
                'codigo' => '9.1',
                'label' => 'Evaluación de riesgo',
                'campos' => [
                    ['id' => 'severidad', 'label' => 'Severidad', 'tipo' => 'select', 'opciones' => ['Sin daño', 'Daño leve', 'Daño moderado', 'Daño grave', 'Muerte'], 'required' => true],
                    ['id' => 'requiere_retiro', 'label' => '¿Requiere retiro de producto?', 'tipo' => 'radio', 'opciones' => ['Sí', 'No']],
                    ['id' => 'detalle_riesgo', 'label' => 'Detalle del riesgo', 'tipo' => 'textarea', 'required' => true],
                ],
            ],
        ],

        /*
        | Producción es **un solo sector**; adentro se divide en dos líneas.
        |
        | Las líneas son subgrupos de tipos (`grupo`) y no sectores aparte: es
        | un solo equipo, con un solo plazo y una sola bandeja de derivación, y
        | además así el `PRODUCCIÓN` del Excel de usuarios matchea el slug
        | `produccion` sin necesidad de un alias.
        |
        | `grupo` es opcional y lo usa el <optgroup> del select de tipo. Los
        | sectores que no lo declaran (todos los demás) no cambian en nada.
        |
        | El campo `motivo` va como texto libre a propósito: Producción todavía
        | no definió la lista cerrada ("Motivo: a desarrollar" en el pedido).
        | Cuando la defina, pasa a `'tipo' => 'select'` con sus `opciones` y no
        | hay que tocar código ni migrar lo ya cargado.
        |
        | ⚠️ Los dos "Otros" tienen claves distintas (`otros_tubos` /
        | `otros_apositos`) porque conviven en el mismo array — y además las
        | claves de tipo son únicas en toda la taxonomía.
        */
        'produccion' => [

            // ── Producción de Tubos ──────────────────────────────────────
            'legibilidad_codigo_barras' => [
                'codigo' => '10.1',
                'grupo' => 'Producción de Tubos',
                'label' => 'Legibilidad de código de barras',
                'campos' => [
                    ['id' => 'motivo', 'label' => 'Motivo', 'tipo' => 'textarea'],
                    ['id' => 'op', 'label' => 'OP (orden de producción)', 'tipo' => 'text', 'required' => true],
                    ['id' => 'fecha', 'label' => 'Fecha', 'tipo' => 'date', 'required' => true],
                    ['id' => 'cantidad_afectada', 'label' => 'Cantidad afectada', 'tipo' => 'number'],
                ],
            ],
            'duplicidad' => [
                'codigo' => '10.2',
                'grupo' => 'Producción de Tubos',
                'label' => 'Duplicidad',
                'campos' => [
                    ['id' => 'motivo', 'label' => 'Motivo', 'tipo' => 'textarea'],
                    ['id' => 'op', 'label' => 'OP (orden de producción)', 'tipo' => 'text', 'required' => true],
                    ['id' => 'fecha', 'label' => 'Fecha', 'tipo' => 'date', 'required' => true],
                    ['id' => 'cantidad_afectada', 'label' => 'Cantidad afectada', 'tipo' => 'number'],
                ],
            ],
            'doble_etiquetado' => [
                'codigo' => '10.3',
                'grupo' => 'Producción de Tubos',
                'label' => 'Doble etiquetado',
                'campos' => [
                    // Sí/No además del detalle: sin el flag no se puede sacar
                    // después cuántos dobles etiquetados fueron por máquina.
                    ['id' => 'falla_maquina', 'label' => '¿Se debió a una falla de máquina?', 'tipo' => 'radio', 'opciones' => ['Sí', 'No'], 'required' => true],
                    ['id' => 'detalle_falla_maquina', 'label' => 'Detalle de la falla', 'tipo' => 'textarea'],
                    ['id' => 'op', 'label' => 'OP (orden de producción)', 'tipo' => 'text', 'required' => true],
                    ['id' => 'fecha', 'label' => 'Fecha', 'tipo' => 'date', 'required' => true],
                    ['id' => 'cantidad_afectada', 'label' => 'Cantidad afectada', 'tipo' => 'number'],
                ],
            ],
            'incumplimiento_produccion_diaria' => [
                'codigo' => '10.4',
                'grupo' => 'Producción de Tubos',
                'label' => 'Incumplimientos en producción diaria',
                'campos' => [
                    ['id' => 'motivo', 'label' => 'Motivo', 'tipo' => 'textarea'],
                    ['id' => 'op', 'label' => 'OP (orden de producción)', 'tipo' => 'text', 'required' => true],
                    ['id' => 'fecha_corte', 'label' => 'Fecha de corte', 'tipo' => 'date', 'required' => true],
                    // La fecha y la hora de retoma van separadas: así la fecha
                    // se carga con el mismo campo enmascarado que el resto del
                    // sistema en vez de con un datetime nativo.
                    ['id' => 'fecha_retoma', 'label' => 'Fecha en que se retoma la producción', 'tipo' => 'date'],
                    ['id' => 'hora_retoma', 'label' => 'Hora en que se retoma la producción', 'tipo' => 'time'],
                    // "Cantidad afectada, si corresponde": opcional a propósito.
                    ['id' => 'cantidad_afectada', 'label' => 'Cantidad afectada (si corresponde)', 'tipo' => 'number'],
                ],
            ],
            'otros_tubos' => [
                'codigo' => '10.5',
                'grupo' => 'Producción de Tubos',
                'label' => 'Otros',
                'campos' => [
                    ['id' => 'motivo', 'label' => 'Motivo / descripción', 'tipo' => 'textarea', 'required' => true],
                ],
            ],

            // ── Producción de Apósitos ───────────────────────────────────
            'falla_maquina_apositos' => [
                'codigo' => '10.6',
                'grupo' => 'Producción de Apósitos',
                'label' => 'Falla de máquina',
                'campos' => [
                    ['id' => 'motivo', 'label' => 'Motivo', 'tipo' => 'textarea', 'required' => true],
                    ['id' => 'fecha', 'label' => 'Fecha', 'tipo' => 'date', 'required' => true],
                    ['id' => 'hora_desde', 'label' => 'Hora desde', 'tipo' => 'time'],
                    ['id' => 'hora_hasta', 'label' => 'Hora hasta', 'tipo' => 'time'],
                ],
            ],
            'otros_apositos' => [
                'codigo' => '10.7',
                'grupo' => 'Producción de Apósitos',
                'label' => 'Otros',
                'campos' => [
                    ['id' => 'motivo', 'label' => 'Motivo / descripción', 'tipo' => 'textarea', 'required' => true],
                ],
            ],
        ],
    ],
];
