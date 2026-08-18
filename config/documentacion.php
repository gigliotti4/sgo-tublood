<?php

/*
|--------------------------------------------------------------------------
| Clasificación documental — tipos y documentación requerida
|--------------------------------------------------------------------------
|
| Fuente de verdad del catálogo de tipos y de qué documentos tiene que
| presentar cada uno. Sale de las planillas de Tublood ("Catálogo de
| documentos" / "Requisitos por tipo de cliente").
|
| Agregar un tipo o un documento es agregar una entrada acá, sin tocar código:
| lo lee App\Support\Documentacion y de ahí salen el select del panel,
| el checklist de la ficha, las reglas de validación y las columnas del Excel.
|
| `documentos` (arriba) es el **nombre canónico** de cada documento: el mismo
| papel se llama igual en todos los tipos. Es lo que usan los encabezados del
| Excel de exportación/importación, que necesitan un nombre único por columna.
|
| Cada tipo lista las claves que exige, con:
|   obligatorio  (bool)    Si falta, el cliente no tiene la documentación completa.
|   vence        (bool)    Si carga fecha de vencimiento. La planilla distingue
|                          "N/A" de "SIN VTO", pero los dos significan lo mismo
|                          para la aplicación: no se carga fecha. **Ojo que el
|                          mismo documento vence en un tipo y no en otro** (la
|                          Habilitación Ministerio vence en Droguería pero no en
|                          Farmacia), por eso el flag es por tipo y no del documento.
|   determina_vencimiento  El documento cuyo vencimiento es el del cliente
|                (bool)    (la columna "LO QUE DETERMINA EL VTO FINAL" de la
|                          planilla). **Como mucho uno por tipo.** Es el que
|                          alimenta `clientes.fecha_vencimiento`, o sea la
|                          campana de "clientes por vencer". Un tipo sin
|                          ninguno (los que no tienen documentos con VTO) no
|                          vence nunca.
|   label                  Override opcional del nombre canónico, solo para la
|                          pantalla (ej. la habilitación del laboratorio de
|                          análisis, que puede ser del Colegio Bioquímico).
|
| Las claves se repiten a propósito entre tipos: `constancia_arca` es el mismo
| documento en todos, así reclasificar un cliente no le borra lo que ya tenía
| cargado de los documentos que los dos tipos comparten.
|
*/

return [

    'documentos' => [
        'constancia_arca' => 'Constancia ARCA',
        'habilitacion_ministerio' => 'Habilitación Ministerio',
        'habilitacion_anmat' => 'Habilitación ANMAT',
        'certificado_funcionamiento' => 'Certificado de Funcionamiento',
        'designacion_director_tecnico' => 'Designación Director Técnico',
        'bpd' => 'BPD',
        'bpf' => 'BPF',
        'bpd_bpf' => 'BPD/BPF',
        'matricula_profesional' => 'Matrícula Profesional',
        'matricula_veterinaria' => 'Matrícula Veterinaria',
        'habilitacion_establecimiento' => 'Habilitación Establecimiento',
        'ddjj' => 'DDJJ',
    ],

    'tipos' => [

        'profesional_independiente' => [
            'label' => 'Profesional Independiente',
            'documentos' => [
                'matricula_profesional' => ['obligatorio' => true, 'vence' => false],
                'ddjj' => ['obligatorio' => true, 'vence' => false],
            ],
        ],

        'laboratorio_analisis_clinicos' => [
            'label' => 'Laboratorio de Análisis Clínicos',
            'documentos' => [
                'constancia_arca' => ['obligatorio' => true, 'vence' => false],
                'habilitacion_ministerio' => ['obligatorio' => true, 'vence' => true, 'determina_vencimiento' => true, 'label' => 'Habilitación Ministerio / Colegio Bioquímico'],
                'designacion_director_tecnico' => ['obligatorio' => false, 'vence' => true],
            ],
        ],

        'drogueria' => [
            'label' => 'Droguería',
            'documentos' => [
                'constancia_arca' => ['obligatorio' => true, 'vence' => false],
                'habilitacion_ministerio' => ['obligatorio' => true, 'vence' => true],
                'habilitacion_anmat' => ['obligatorio' => true, 'vence' => true],
                'bpd_bpf' => ['obligatorio' => false, 'vence' => true],
                'certificado_funcionamiento' => ['obligatorio' => true, 'vence' => true, 'determina_vencimiento' => true],
            ],
        ],

        'centro_medico' => [
            'label' => 'Centro Médico',
            'documentos' => [
                'constancia_arca' => ['obligatorio' => true, 'vence' => false],
                'habilitacion_ministerio' => ['obligatorio' => true, 'vence' => false],
            ],
        ],

        'farmacia' => [
            'label' => 'Farmacia',
            'documentos' => [
                'constancia_arca' => ['obligatorio' => true, 'vence' => false],
                'habilitacion_ministerio' => ['obligatorio' => true, 'vence' => false],
            ],
        ],

        'distribuidor' => [
            'label' => 'Distribuidor',
            'documentos' => [
                'constancia_arca' => ['obligatorio' => true, 'vence' => false],
                'habilitacion_ministerio' => ['obligatorio' => true, 'vence' => false],
                'habilitacion_anmat' => ['obligatorio' => false, 'vence' => true, 'determina_vencimiento' => true],
                'bpd' => ['obligatorio' => false, 'vence' => true],
                'designacion_director_tecnico' => ['obligatorio' => false, 'vence' => true],
                'certificado_funcionamiento' => ['obligatorio' => false, 'vence' => true, 'label' => 'Certificado de habilitación de funcionamiento'],
            ],
        ],

        'importador' => [
            'label' => 'Importador',
            'documentos' => [
                'constancia_arca' => ['obligatorio' => true, 'vence' => false],
                'habilitacion_anmat' => ['obligatorio' => true, 'vence' => true],
                'certificado_funcionamiento' => ['obligatorio' => true, 'vence' => true],
                'bpf' => ['obligatorio' => true, 'vence' => true, 'determina_vencimiento' => true],
            ],
        ],

        'laboratorio_fabricante' => [
            'label' => 'Laboratorio Fabricante',
            'documentos' => [
                'constancia_arca' => ['obligatorio' => true, 'vence' => false],
                'habilitacion_anmat' => ['obligatorio' => true, 'vence' => true],
                'certificado_funcionamiento' => ['obligatorio' => true, 'vence' => true],
                'bpf' => ['obligatorio' => true, 'vence' => true, 'determina_vencimiento' => true],
            ],
        ],

        'veterinaria' => [
            'label' => 'Veterinaria',
            'documentos' => [
                'constancia_arca' => ['obligatorio' => true, 'vence' => false],
                'matricula_veterinaria' => ['obligatorio' => true, 'vence' => false],
                'habilitacion_establecimiento' => ['obligatorio' => true, 'vence' => false],
                'ddjj' => ['obligatorio' => true, 'vence' => false],
            ],
        ],

        'institucion' => [
            'label' => 'Institución (Hospital, Sanatorio, Clínica)',
            'documentos' => [
                'constancia_arca' => ['obligatorio' => true, 'vence' => false],
                'habilitacion_ministerio' => ['obligatorio' => true, 'vence' => false],
            ],
        ],

    ],

];
