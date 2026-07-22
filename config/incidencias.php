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
*/

return [

    'prioridades' => [
        'critica' => 'Crítica',
        'alta' => 'Alta',
        'media' => 'Media',
        'baja' => 'Baja',
    ],

    'tipos_caso' => [
        'Producto defectuoso',
        'Riesgo sanitario',
        'Consulta técnica',
        'Documentación',
        'Demora logística',
        'Error de uso',
        'Otro',
    ],

    // Prioridad sugerida por tipo de caso (el usuario puede cambiarla).
    'prioridad_sugerida' => [
        'Riesgo sanitario' => 'critica',
        'Producto defectuoso' => 'alta',
        'Demora logística' => 'media',
        'Documentación' => 'baja',
    ],

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
                'campos' => [
                    ['id' => 'numero_remito', 'label' => 'Número de remito', 'tipo' => 'text'],
                    ['id' => 'numero_cliente', 'label' => 'Número de cliente', 'tipo' => 'text', 'required' => true],
                ],
            ],
        ],

        'logistica' => [
            'demora_entrega' => [
                'codigo' => '3.1',
                'label' => 'Demora en entrega',
                'campos' => [
                    ['id' => 'numero_cliente', 'label' => 'Número de cliente', 'tipo' => 'text'],
                    ['id' => 'razon_social', 'label' => 'Razón social', 'tipo' => 'text'],
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
                'campos' => [
                    ['id' => 'numero_remito', 'label' => 'Número de remito', 'tipo' => 'text', 'required' => true],
                    ['id' => 'cliente', 'label' => 'Cliente', 'tipo' => 'text', 'required' => true],
                ],
            ],
            'falla_recepcion' => [
                'codigo' => '4.2',
                'label' => 'Falla en recepción',
                'campos' => [
                    ['id' => 'codigo_producto', 'label' => 'Código de producto', 'tipo' => 'text', 'required' => true],
                    ['id' => 'descripcion', 'label' => 'Descripción', 'tipo' => 'text', 'required' => true],
                    ['id' => 'cantidades', 'label' => 'Cantidades', 'tipo' => 'number', 'required' => true],
                    ['id' => 'numero_proceso_cliente', 'label' => 'N° proceso / N° cliente', 'tipo' => 'text', 'required' => true],
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
                'campos' => [
                    ['id' => 'numero_cliente', 'label' => 'Número de cliente', 'tipo' => 'text', 'required' => true],
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
    ],
];
