<?php

/**
 * Catálogo de la configuración administrable de marca y textos.
 *
 * Mismo patrón que `config/documentacion.php` + `App\Support\Documentacion`:
 * acá se declara **qué claves existen**, cómo se llaman en la pantalla, de qué
 * tipo son y — lo importante — **su valor por defecto**, que es el texto que
 * estaba hardcodeado antes de que esto existiera.
 *
 * La tabla `configuraciones` solo guarda lo que alguien cambió: una clave sin
 * fila cae en el `default` de acá. Por eso agregar una clave nueva es solo
 * config, sin migración de datos ni fila semilla.
 *
 * `tipo`:
 *  - `texto`    → <input>
 *  - `textarea` → <textarea>
 *  - `imagen`   → subida de archivo al disco `public`
 */
return [

    'grupos' => [
        'marca' => 'Marca',
        'login' => 'Pantalla de login',
        'portal' => 'Portal público',
        'pdf' => 'PDF de observaciones',
    ],

    'claves' => [

        // ── Marca ──────────────────────────────────────────────────────────
        'empresa_nombre' => [
            'grupo' => 'marca',
            'label' => 'Nombre de la empresa',
            'tipo' => 'texto',
            'default' => 'Tublood SA',
        ],
        'empresa_bajada' => [
            'grupo' => 'marca',
            'label' => 'Bajada de la empresa',
            'tipo' => 'texto',
            'default' => 'Dispositivos Médicos',
            'hint' => 'Aparece debajo del nombre en el login.',
        ],
        'app_nombre' => [
            'grupo' => 'marca',
            'label' => 'Nombre del sistema',
            'tipo' => 'texto',
            'default' => 'SGO Tublood',
            'hint' => 'Título del sidebar y de la pestaña del navegador.',
        ],
        'app_bajada' => [
            'grupo' => 'marca',
            'label' => 'Bajada del sistema',
            'tipo' => 'texto',
            'default' => 'Gestión de Observaciones',
        ],
        'logo' => [
            'grupo' => 'marca',
            'label' => 'Logo',
            'tipo' => 'imagen',
            'default' => null,
            'hint' => 'PNG o SVG, idealmente cuadrado. Sin logo se usa el ícono por defecto.',
        ],
        'favicon' => [
            'grupo' => 'marca',
            'label' => 'Favicon',
            'tipo' => 'imagen',
            'default' => null,
            'hint' => 'El ícono de la pestaña del navegador. PNG, ICO o SVG.',
        ],

        // ── Login ──────────────────────────────────────────────────────────
        'login_kicker' => [
            'grupo' => 'login',
            'label' => 'Volanta',
            'tipo' => 'texto',
            'default' => 'Sistema de Gestión',
            'hint' => 'La línea chica en mayúsculas arriba del titular.',
        ],
        'login_titulo' => [
            'grupo' => 'login',
            'label' => 'Titular',
            'tipo' => 'texto',
            'default' => 'Observaciones',
        ],
        'login_titulo_destacado' => [
            'grupo' => 'login',
            'label' => 'Titular destacado',
            'tipo' => 'texto',
            'default' => 'bajo control.',
            'hint' => 'La segunda línea del titular, en color.',
        ],
        'login_parrafo' => [
            'grupo' => 'login',
            'label' => 'Párrafo',
            'tipo' => 'textarea',
            'default' => 'Plataforma interna para la gestión, clasificación y seguimiento de observaciones y no conformidades según normativas vigentes.',
        ],
        'login_features' => [
            'grupo' => 'login',
            'label' => 'Puntos destacados',
            'tipo' => 'textarea',
            'default' => "Registro y clasificación de observaciones\nGestión de no conformidades y CAPA\nTrazabilidad completa por expediente",
            'hint' => 'Uno por línea. Cada línea se dibuja con su tilde.',
        ],
        'login_footer' => [
            'grupo' => 'login',
            'label' => 'Pie de página',
            'tipo' => 'texto',
            'default' => 'Tublood SA · Todos los derechos reservados',
            'hint' => 'El año se antepone solo (© 2026 …).',
        ],
        'login_pie_sistema' => [
            'grupo' => 'login',
            'label' => 'Pie del formulario',
            'tipo' => 'texto',
            'default' => 'SGO · Sistema de Gestión de Observaciones',
            'hint' => 'La línea gris del final de la columna derecha.',
        ],

        // ── Portal público ─────────────────────────────────────────────────
        'portal_titulo' => [
            'grupo' => 'portal',
            'label' => 'Título',
            'tipo' => 'texto',
            'default' => 'Cargar observación',
        ],
        'portal_bajada' => [
            'grupo' => 'portal',
            'label' => 'Bajada',
            'tipo' => 'texto',
            'default' => 'Espacio exclusivo para clientes de Tublood',
        ],

        // ── PDF ────────────────────────────────────────────────────────────
        'pdf_encabezado' => [
            'grupo' => 'pdf',
            'label' => 'Subtítulo del encabezado',
            'tipo' => 'texto',
            'default' => 'Sistema de Gestión de Observaciones',
            'hint' => 'Debajo del nombre de la empresa, arriba de cada página.',
        ],
        'pdf_pie' => [
            'grupo' => 'pdf',
            'label' => 'Pie de página',
            'tipo' => 'texto',
            'default' => 'Documento generado automáticamente por el SGO — Tublood SA',
            'hint' => 'El número de página se agrega solo al final.',
        ],
    ],
];
