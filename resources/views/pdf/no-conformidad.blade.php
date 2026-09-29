{{--
    Informe de Desvío — el PDF de una No Conformidad.

    ⚠️ Replica el layout de `Formulario_Informe_de_Desvio.xlsx`: cabecera en
    grilla y después las siete secciones numeradas, con los mismos títulos en
    mayúscula y en el mismo orden. Es el papel que la gente de Tublood ya usa,
    así que el orden no es cosmético — cambiarlo rompe el reconocimiento.

    DomPDF renderiza CSS 2.1: NO hay flexbox ni grid, así que la maquetación va
    con tablas y `width` en porcentajes. La fuente es DejaVu Sans porque es la
    única que trae DomPDF con cobertura UTF-8 completa (Helvetica rompe las
    tildes y la ñ).

    Los colores están escritos a mano, igual que en `pdf/observacion.blade.php`:
    son los del manual de marca, PANTONE Reflex Blue C #001489 y PANTONE 417 C
    #65665C. Si cambia la paleta en resources/css/app.css hay que tocarlos acá
    también — esta plantilla no comparte una línea con Show.vue.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe de Desvío {{ $nc->numero }}</title>
    <style>
        @page { margin: 28mm 16mm 20mm 16mm; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9.5pt;
            color: #1f2937;
            line-height: 1.45;
        }

        header {
            position: fixed;
            top: -18mm; left: 0; right: 0;
            border-bottom: 1.5pt solid #001489;
            padding-bottom: 4mm;
        }
        header .marca { font-size: 13pt; font-weight: bold; color: #001489; }
        header .sub { font-size: 7.5pt; color: #65665c; }
        header .numero { font-size: 10pt; font-weight: bold; text-align: right; }

        footer {
            position: fixed;
            bottom: -12mm; left: 0; right: 0;
            font-size: 7pt; color: #9ca3af;
            border-top: 0.5pt solid #e5e7eb;
            padding-top: 2mm;
        }
        .pagina:after { content: counter(page); }

        h1 { font-size: 13pt; margin: 0 0 2mm; }

        /* Títulos de sección: el número va pegado, como en el formulario. */
        h2 {
            font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.5pt;
            color: #ffffff; background: #65665c;
            font-weight: bold;
            margin: 5mm 0 2mm; padding: 1.5mm 2.5mm;
        }

        table { width: 100%; border-collapse: collapse; }

        /* Cabecera del formulario: pares etiqueta/valor en dos columnas. */
        table.cabecera td { padding: 1.2mm 0; vertical-align: top; }
        table.cabecera td.k { width: 17%; color: #65665c; font-size: 8pt; }
        table.cabecera td.v { width: 33%; }

        table.grilla th {
            background: #f9fafb; color: #65665c;
            font-size: 7.5pt; text-align: left; font-weight: bold;
            padding: 1.5mm 2mm; border-bottom: 0.5pt solid #e5e7eb;
        }
        table.grilla td {
            padding: 1.5mm 2mm; font-size: 8pt;
            border-bottom: 0.5pt solid #f3f4f6;
            vertical-align: top;
        }

        /* Las 6M: etiqueta angosta a la izquierda, texto a la derecha. */
        table.factores td { padding: 1.5mm 2mm; border-bottom: 0.5pt solid #f3f4f6; vertical-align: top; }
        table.factores td.k { width: 18%; color: #65665c; font-size: 8pt; font-weight: bold; }

        .texto { white-space: pre-line; }
        .vacio { color: #9ca3af; font-style: italic; }
        .mono { font-family: 'DejaVu Sans Mono', monospace; font-size: 8pt; }
        .chip {
            display: inline-block;
            padding: 1mm 2.5mm; margin-right: 1.5mm;
            background: #f3f4f6; color: #374151;
            font-size: 7.5pt;
        }
        .chip-alerta { background: #fef2f2; color: #b91c1c; }
        .chip-ok { background: #ecfdf5; color: #047857; }
        .nota { font-size: 7.5pt; color: #9ca3af; margin-top: 1mm; }
        .imagen { margin-bottom: 4mm; }
        .imagen img { max-width: 100%; max-height: 90mm; }
    </style>
</head>
<body>

<header>
    <table>
        <tr>
            @if ($logo)
                {{-- Incrustado en base64 por el controller: DomPDF corre con
                     enable_remote=false y una <img> con URL saldría vacía. --}}
                <td style="width: 14mm; vertical-align: middle;">
                    <img src="{{ $logo }}" style="max-width: 11mm; max-height: 11mm;">
                </td>
            @endif
            <td>
                <div class="marca">{{ $marca['empresa_nombre'] }}</div>
                <div class="sub">{{ $marca['pdf_encabezado'] }}</div>
            </td>
            <td class="numero">
                Informe de Desvío<br>
                <span class="mono">{{ $nc->numero ?? 'Sin número' }}</span>
            </td>
        </tr>
    </table>
</header>

<footer>
    {{ $marca['pdf_pie'] }} · Emitido el {{ $emitido }} · Página <span class="pagina"></span>
</footer>

<h1>Informe de Desvío</h1>

{{-- Una NC en borrador o rechazada no tiene número, y eso no es un dato
     faltante: se asigna al aprobarla. Se dice, en vez de dejar un hueco. --}}
@if (! $nc->numero)
    <p class="nota">Esta No Conformidad todavía no tiene número: se asigna al aprobarse la apertura.</p>
@endif

<table class="cabecera">
    <tr>
        <td class="k">Desvío N°</td>
        <td class="v mono">{{ $nc->numero ?? '—' }}</td>
        <td class="k">Fecha</td>
        <td class="v">{{ $nc->fecha_deteccion?->format('d/m/Y') ?? '—' }}</td>
    </tr>
    <tr>
        <td class="k">Tipo de desvío</td>
        <td class="v">{{ $nc->tipo_desvio === 'interno' ? 'INTERNO' : 'EXTERNO' }}</td>
        <td class="k">Sector</td>
        <td class="v">{{ $nc->sector?->nombre ?? '—' }}</td>
    </tr>
    <tr>
        <td class="k">Motivo</td>
        <td class="v" colspan="3">{{ $nc->motivo }}</td>
    </tr>
    <tr>
        <td class="k">Cliente</td>
        <td class="v">{{ $nc->cliente?->razon_social ?? 'N/A' }}</td>
        <td class="k">Proveedor</td>
        <td class="v">{{ $nc->proveedor?->razon_social ?? 'N/A' }}</td>
    </tr>
    <tr>
        <td class="k">Estado</td>
        <td class="v">{{ $nc->etiquetaEstado() }}</td>
        <td class="k">Creada por</td>
        <td class="v">{{ $nc->creador?->name ?? '—' }} {{ $nc->creador?->apellido }}</td>
    </tr>
    <tr>
        <td class="k">Responsable</td>
        {{-- Sin responsable la gestiona Garantía de Calidad: se dice, en vez de
             dejar un guion que se leería como un dato que falta. --}}
        <td class="v" colspan="3">
            {{ $nc->responsable ? trim($nc->responsable->name.' '.$nc->responsable->apellido) : 'Garantía de Calidad' }}
        </td>
    </tr>
    @if ($nc->reemplazaA)
        <tr>
            <td class="k">Reemplaza a</td>
            <td class="v mono" colspan="3">{{ $nc->reemplazaA->numero ?? 'un desvío en borrador' }}</td>
        </tr>
    @endif
</table>

<h2>1) Descripción del problema</h2>
<div class="texto">{{ $nc->descripcion }}</div>

<h2>2) Investigación</h2>
@if (filled($nc->investigacion))
    <div class="texto">{{ $nc->investigacion }}</div>
@else
    <p class="vacio">Sin completar.</p>
@endif

@if (filled($nc->alcance) || filled($nc->afectados))
    <table class="cabecera" style="margin-top: 2mm;">
        @if (filled($nc->alcance))
            <tr><td class="k">Alcance</td><td class="v texto" colspan="3">{{ $nc->alcance }}</td></tr>
        @endif
        @if (filled($nc->afectados))
            <tr><td class="k">Afectados</td><td class="v texto" colspan="3">{{ $nc->afectados }}</td></tr>
        @endif
    </table>
@endif

<table class="cabecera" style="margin-top: 2mm;">
    <tr>
        <td class="k">Riesgo involucrado</td>
        <td class="v texto" colspan="3">{{ $nc->evaluacion_riesgo ?: '—' }}</td>
    </tr>
    <tr>
        <td class="k">Grave</td>
        <td class="v">{{ $nc->es_grave ? 'SÍ' : 'NO' }}</td>
        <td class="k">Repetitivo</td>
        <td class="v">{{ $nc->es_repetitivo ? 'SÍ' : 'NO' }}</td>
    </tr>
    <tr>
        <td class="k">¿Requiere CAPA?</td>
        <td class="v" colspan="3">{{ $nc->requiere_capa ? 'SÍ' : 'NO' }}</td>
    </tr>
</table>

<h2>3) Acción inmediata de contención / corrección</h2>
@if ($nc->contenciones->isEmpty())
    <p class="vacio">Sin acciones de contención registradas.</p>
@else
    <table class="grilla">
        <tr>
            <th style="width: 18%;">Fecha</th>
            <th>Acción</th>
            <th style="width: 25%;">Responsable</th>
        </tr>
        @foreach ($nc->contenciones as $c)
            <tr>
                <td>{{ $c->fecha?->format('d/m/Y') ?? '—' }}</td>
                <td class="texto">{{ $c->accion }}</td>
                <td>{{ $c->responsable ? trim($c->responsable->name.' '.$c->responsable->apellido) : '—' }}</td>
            </tr>
        @endforeach
    </table>
@endif

<h2>4) Análisis de causa raíz — CAPA</h2>
<table class="factores">
    @foreach ($factores as $clave => $label)
        <tr>
            <td class="k">{{ $label }}</td>
            <td class="texto">{{ data_get($nc->causa_raiz_factores, $clave) ?: '—' }}</td>
        </tr>
    @endforeach
    <tr>
        <td class="k">Resumen del análisis</td>
        <td class="texto">{{ $nc->causa_raiz ?: '—' }}</td>
    </tr>
    @if (filled($nc->conclusion))
        <tr>
            <td class="k">Conclusión</td>
            <td class="texto">{{ $nc->conclusion }}</td>
        </tr>
    @endif
</table>

<h2>5) Plan de acción correctiva / preventiva</h2>
@if ($nc->acciones->isEmpty())
    <p class="vacio">Sin acciones cargadas.</p>
@else
    <table class="grilla">
        <tr>
            <th>Acción</th>
            <th style="width: 20%;">Responsable</th>
            <th style="width: 14%;">Plazo</th>
            <th style="width: 16%;">Estado</th>
        </tr>
        @foreach ($nc->acciones as $a)
            <tr>
                <td class="texto">
                    {{ $a->descripcion }}
                    @if (filled($a->avance))
                        <div class="nota">Avance: {{ $a->avance }}</div>
                    @endif
                    @if (filled($a->motivo_cancelacion))
                        <div class="nota">Cancelada: {{ $a->motivo_cancelacion }}</div>
                    @endif
                </td>
                <td>{{ $a->responsable ? trim($a->responsable->name.' '.$a->responsable->apellido) : '—' }}</td>
                <td>
                    {{ $a->fecha_prevista?->format('d/m/Y') ?? '—' }}
                    @if ($a->fecha_real)
                        <div class="nota">Real: {{ $a->fecha_real->format('d/m/Y') }}</div>
                    @endif
                </td>
                <td>{{ $estadosAccion[$a->estado] ?? $a->estado }}</td>
            </tr>
        @endforeach
    </table>
@endif

{{--
    Va con el plan y no en la sección 6 porque es donde se carga: es la fecha en
    que se va a comprobar si estas acciones sirvieron. ⚠️ No confundir con la
    "Fecha de seguimiento" de abajo, que es cuándo se verificó.
--}}
<table class="cabecera" style="margin-top: 6px;">
    <tr>
        <td class="k">Fecha prevista de verificación</td>
        <td class="v">{{ $nc->fecha_verificacion_prevista?->format('d/m/Y') ?? '—' }}</td>
    </tr>
</table>

<h2>6) Seguimiento del plan de acción</h2>
<table class="cabecera">
    <tr>
        <td class="k">Método de seguimiento</td>
        <td class="v texto" colspan="3">{{ $nc->metodo_seguimiento ?: '—' }}</td>
    </tr>
    <tr>
        <td class="k">Fecha de seguimiento</td>
        <td class="v">{{ $nc->fecha_seguimiento?->format('d/m/Y') ?? '—' }}</td>
        <td class="k">Evidencia revisada</td>
        <td class="v texto">{{ $nc->evidencia_revisada ?: '—' }}</td>
    </tr>
</table>

<h2>7) Verificación de la eficacia de la acción tomada</h2>
<table class="cabecera">
    <tr>
        <td class="k">Resultado</td>
        <td class="v">
            @if ($nc->resultado_eficacia)
                <span class="chip {{ $nc->resultado_eficacia === 'eficaz' ? 'chip-ok' : ($nc->resultado_eficacia === 'ineficaz' ? 'chip-alerta' : '') }}">
                    {{ strtoupper($resultados[$nc->resultado_eficacia] ?? $nc->resultado_eficacia) }}
                </span>
            @else
                —
            @endif
        </td>
        {{-- La celda del formulario: solo se llena cuando el resultado fue
             ineficaz y ya se abrió el desvío que reemplaza a éste. --}}
        <td class="k">Nuevo desvío N°</td>
        <td class="v mono">{{ $nc->reemplazadaPor?->numero ?? ($nc->reemplazadaPor ? 'En borrador' : '—') }}</td>
    </tr>
    <tr>
        <td class="k">Fecha de cierre</td>
        <td class="v">{{ $nc->cerrada_at?->format('d/m/Y') ?? '—' }}</td>
        <td class="k">Responsable del cierre</td>
        <td class="v">{{ $nc->cerradaPorUsuario ? trim($nc->cerradaPorUsuario->name.' '.$nc->cerradaPorUsuario->apellido) : '—' }}</td>
    </tr>
    <tr>
        <td class="k">Observaciones</td>
        <td class="v texto" colspan="3">{{ $nc->observaciones_verificacion ?: ($nc->observaciones_finales ?: '—') }}</td>
    </tr>
    @if (filled($nc->resultado_final))
        <tr>
            <td class="k">Resultado final</td>
            <td class="v texto" colspan="3">{{ $nc->resultado_final }}</td>
        </tr>
    @endif
</table>

{{-- De acá para abajo no es parte del formulario en papel: son cosas que el
     sistema sabe y el papel no puede dar. --}}

@if ($nc->observaciones->isNotEmpty())
    <h2>Anexo · Observaciones vinculadas</h2>
    <table class="grilla">
        <tr><th style="width: 18%;">Número</th><th>Título</th><th style="width: 22%;">Estado</th></tr>
        @foreach ($nc->observaciones as $o)
            <tr>
                <td class="mono">{{ $o->numero }}</td>
                <td>{{ $o->titulo }}</td>
                <td>{{ $o->etiquetaEstado() }}</td>
            </tr>
        @endforeach
    </table>
@endif

@if (count($imagenes))
    <h2>Anexo · Imágenes</h2>
    @foreach ($imagenes as $img)
        <div class="imagen">
            <img src="{{ $img['src'] }}">
            <div class="nota">{{ $img['nombre'] }}</div>
        </div>
    @endforeach
@endif

@if (count($imagenesOmitidas))
    <p class="nota">
        No se incrustaron (formato no soportado o demasiado pesadas):
        {{ implode(', ', $imagenesOmitidas) }}
    </p>
@endif

{{-- §7 del instructivo exige trazabilidad, y el papel no puede darla: por eso
     la bitácora va al final del expediente. --}}
<h2>Anexo · Bitácora</h2>
<table class="grilla">
    <tr>
        <th style="width: 20%;">Fecha</th>
        <th style="width: 22%;">Quién</th>
        <th style="width: 22%;">Acción</th>
        <th>Detalle</th>
    </tr>
    @forelse ($nc->historial as $h)
        <tr>
            <td>{{ $h->created_at?->format('d/m/Y H:i') }}</td>
            <td>{{ $h->user ? trim($h->user->name.' '.$h->user->apellido) : 'Sistema' }}</td>
            <td>{{ $accionLabels[$h->accion] ?? $h->accion }}</td>
            <td class="texto">{{ $h->nota ?: '—' }}</td>
        </tr>
    @empty
        <tr><td colspan="4" class="vacio">Sin actividad registrada.</td></tr>
    @endforelse
</table>

</body>
</html>
