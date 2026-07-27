{{--
    Plantilla del PDF de una observación.

    DomPDF renderiza CSS 2.1: NO hay flexbox ni grid, así que la maquetación va
    con tablas y `width` en porcentajes. La fuente es DejaVu Sans porque es la
    única que trae DomPDF con cobertura UTF-8 completa (Helvetica rompe las
    tildes y la ñ).
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Observación {{ $observacion->numero }}</title>
    <style>
        @page { margin: 28mm 16mm 20mm 16mm; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9.5pt;
            color: #1f2937;
            line-height: 1.45;
        }

        /* Encabezado y pie repetidos en cada página */
        header {
            position: fixed;
            top: -18mm; left: 0; right: 0;
            border-bottom: 1.5pt solid #4f46e5;
            padding-bottom: 4mm;
        }
        header .marca { font-size: 13pt; font-weight: bold; color: #4f46e5; }
        header .sub { font-size: 7.5pt; color: #6b7280; }
        header .numero { font-size: 10pt; font-weight: bold; text-align: right; }

        footer {
            position: fixed;
            bottom: -12mm; left: 0; right: 0;
            font-size: 7pt; color: #9ca3af;
            border-top: 0.5pt solid #e5e7eb;
            padding-top: 2mm;
        }
        .pagina:after { content: counter(page); }

        h1 { font-size: 13pt; margin: 0 0 1mm; }
        h2 {
            font-size: 8pt; text-transform: uppercase; letter-spacing: 0.5pt;
            color: #6b7280; font-weight: bold;
            margin: 6mm 0 2mm; padding-bottom: 1mm;
            border-bottom: 0.5pt solid #e5e7eb;
        }

        .chip {
            display: inline-block;
            padding: 1mm 2.5mm; margin-right: 1.5mm;
            border-radius: 2mm;
            background: #f3f4f6; color: #374151;
            font-size: 7.5pt;
        }
        .chip-alerta { background: #fef2f2; color: #b91c1c; }

        table { width: 100%; border-collapse: collapse; }

        /* Pares etiqueta/valor */
        table.datos td { padding: 1.2mm 0; vertical-align: top; }
        table.datos td.k { width: 32%; color: #6b7280; font-size: 8pt; }
        table.datos td.v { width: 68%; }

        /* Grillas de datos */
        table.grilla { margin-top: 1mm; }
        table.grilla th {
            background: #f9fafb; color: #6b7280;
            font-size: 7.5pt; text-align: left; font-weight: bold;
            padding: 1.5mm 2mm; border-bottom: 0.5pt solid #e5e7eb;
        }
        table.grilla td {
            padding: 1.5mm 2mm; font-size: 8pt;
            border-bottom: 0.5pt solid #f3f4f6;
        }

        .caja { border: 0.5pt solid #e5e7eb; border-radius: 2mm; padding: 3mm; }
        .aviso {
            border: 0.5pt solid #fcd34d; background: #fffbeb;
            border-radius: 2mm; padding: 2.5mm; margin-bottom: 2.5mm;
            font-size: 8pt; color: #92400e;
        }
        .descripcion { white-space: pre-line; }
        .vacio { color: #9ca3af; font-style: italic; }
        .mono { font-family: 'DejaVu Sans Mono', monospace; font-size: 8pt; }
    </style>
</head>
<body>

<header>
    <table>
        <tr>
            <td>
                <div class="marca">Tublood SA</div>
                <div class="sub">Sistema de Gestión de Observaciones</div>
            </td>
            <td class="numero">
                Observación {{ $observacion->numero }}<br>
                <span class="sub">Emitido el {{ $emitido }}</span>
            </td>
        </tr>
    </table>
</header>

<footer>
    Documento generado automáticamente por el SGO — Tublood SA · Página <span class="pagina"></span>
</footer>

<h1>{{ $observacion->titulo }}</h1>
<div>
    <span class="chip">{{ $observacion->origen === 'interna' ? 'Interna' : 'Externa' }}</span>
    <span class="chip">{{ $estados[$observacion->estado] ?? $observacion->estado }}</span>
    <span class="chip">{{ $tipoLabels[$observacion->tipo] ?? $observacion->tipo }}</span>
    @if ($observacion->tecnovigilancia)
        <span class="chip chip-alerta">Tecnovigilancia</span>
    @endif
</div>

<h2>Gestión</h2>
<table class="datos">
    <tr>
        <td class="k">Responsable</td>
        <td class="v">{{ $observacion->responsable?->name ?? 'Sin asignar' }}</td>
        <td class="k">Prioridad</td>
        <td class="v">{{ $observacion->prioridad ? ($prioridades[$observacion->prioridad] ?? $observacion->prioridad) : 'Sin clasificar' }}</td>
    </tr>
    <tr>
        <td class="k">Sector</td>
        <td class="v">{{ $observacion->sector?->nombre ?? '—' }}</td>
        <td class="k">Tipo de caso</td>
        <td class="v">{{ $observacion->tipo_caso ?? 'Sin clasificar' }}</td>
    </tr>
    <tr>
        <td class="k">Fecha de creación</td>
        <td class="v">{{ $observacion->created_at?->format('d/m/Y') ?? '—' }}</td>
        <td class="k">Vence</td>
        <td class="v">{{ $observacion->vence_at?->format('d/m/Y') ?? 'Sin plazo' }}</td>
    </tr>
</table>

<h2>Descripción</h2>
<div class="descripcion">{{ $observacion->descripcion }}</div>

@if (!empty($observacion->datos_especificos))
    <h2>Datos específicos</h2>
    <table class="datos">
        @foreach ($observacion->datos_especificos as $clave => $valor)
            <tr>
                <td class="k">{{ ucfirst(str_replace('_', ' ', $clave)) }}</td>
                <td class="v">{{ ($valor === null || $valor === '') ? '—' : $valor }}</td>
            </tr>
        @endforeach
    </table>
@endif

@if ($observacion->productos->isNotEmpty())
    <h2>Productos ({{ $observacion->productos->count() }})</h2>
    <table class="grilla">
        <thead>
            <tr>
                <th>Código</th>
                <th>Producto</th>
                <th>Cant.</th>
                <th>Presentación</th>
                <th>Lote</th>
                <th>Vencimiento</th>
                <th>Remito</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($observacion->productos as $producto)
                <tr>
                    <td class="mono">{{ $producto->codigo ?? '—' }}</td>
                    <td>{{ $producto->producto }}</td>
                    <td>{{ $producto->cantidad_afectada }}</td>
                    <td>{{ $producto->tipo_presentacion ? ($presentaciones[$producto->tipo_presentacion] ?? $producto->tipo_presentacion) : '—' }}</td>
                    <td class="mono">{{ $producto->lote }}</td>
                    <td>{{ $producto->fecha_vencimiento?->format('d/m/Y') ?? '—' }}</td>
                    <td class="mono">{{ $producto->numero_remito ?? '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@php
    // Una observación interna no tiene contacto externo: sin esto imprimía un
    // bloque "Cliente" con guiones en todos los campos.
    $hayDatosDeContacto = $observacion->cliente
        || $observacion->contacto_nombre
        || $observacion->contacto_email
        || $observacion->contacto_numero_cliente
        || $observacion->contacto_telefono;
@endphp

<h2>Cliente</h2>
<div class="caja">
    @if (! $hayDatosDeContacto)
        <span class="vacio">Sin cliente asociado (observación interna).</span>
    @elseif ($observacion->cliente)
        <strong>{{ $observacion->cliente->razon_social }}</strong>
        <table class="datos" style="margin-top: 1.5mm;">
            <tr>
                <td class="k">N° de cliente</td>
                <td class="v mono">{{ $observacion->cliente->numero }}</td>
                <td class="k">Teléfono</td>
                <td class="v">{{ $observacion->cliente->telefono ?? '—' }}</td>
            </tr>
            <tr>
                <td class="k">Email</td>
                <td class="v" colspan="3">{{ $observacion->cliente->mail ?? '—' }}</td>
            </tr>
        </table>
    @else
        @if ($observacion->contacto_numero_cliente)
            <div class="aviso">
                El N° de cliente ingresado ({{ $observacion->contacto_numero_cliente }}) no coincide con ningún
                cliente registrado. Los datos de abajo son los que cargó el contacto.
            </div>
        @endif
        <table class="datos">
            <tr>
                <td class="k">Nombre</td>
                <td class="v">{{ $observacion->contacto_nombre ?: '—' }}</td>
                <td class="k">Teléfono</td>
                <td class="v">{{ $observacion->contacto_telefono ?: '—' }}</td>
            </tr>
            <tr>
                <td class="k">Email</td>
                <td class="v" colspan="3">{{ $observacion->contacto_email ?: '—' }}</td>
            </tr>
        </table>
    @endif
</div>

<h2>Archivos adjuntos</h2>
@if ($observacion->attachments->isNotEmpty())
    {{-- Solo se listan: los archivos no se embeben en el PDF. --}}
    <table class="grilla">
        <thead>
            <tr><th>Archivo</th><th style="width: 20%;">Tamaño</th></tr>
        </thead>
        <tbody>
            @foreach ($observacion->attachments as $adjunto)
                <tr>
                    <td>{{ $adjunto->original_name }}</td>
                    <td>{{ $adjunto->size < 1024 * 1024
                        ? number_format($adjunto->size / 1024, 1, ',', '.') . ' KB'
                        : number_format($adjunto->size / 1048576, 1, ',', '.') . ' MB' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="vacio" style="font-size: 7.5pt; margin-top: 2mm;">
        Los archivos se listan como referencia; se descargan desde el sistema.
    </p>
@else
    <p class="vacio">Sin archivos adjuntos.</p>
@endif

</body>
</html>
